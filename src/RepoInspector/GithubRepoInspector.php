<?php

namespace Github\Utils\RepoInspector;

use Http\Client\HttpClient;
use Http\Client\Common\HttpMethodsClient;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\MessageFactoryDiscovery;
use Symfony\Component\DomCrawler;
use Github\Exception\ExceptionInterface as GithubAPIException;
use Github\Utils\GithubWrapperInterface;

/**
 * Statistical analysis tool for github repositories.
 */
class GithubRepoInspector implements GithubRepoInspectorInterface
{
    /**
     * Scores calculation constants.
     */
    const R_HOT_DAYS = 7;
    const R_HOT_GRAVITY = 1.8;
    const R_POP_STARS_FACTOR = 1.5;
    const R_POP_SUBSCRIBERS_FACTOR = 1.6;
    const R_POP_FORKS_FACTOR = 1.7;
    const R_MATURITY_COMMITS_FACTOR = 1.2;
    const R_MATURITY_RELEASES_FACTOR = 1.5;
    const R_MATURITY_CONTRIBS_FACTOR = 1.7;
    const R_MATURITY_SIZE_FACTOR = 1.2;
    const R_ACTIVITY_WEEK_MIN = 15;

    /**
     * @var GithubWrapperInterface
     */
    protected $github;

    /**
     * @var HttpMethodsClient
     */
    protected $http;

    /**
     * Constructor.
     *
     * @param GithubWrapperInterface       $github
     * @param HttpClient|HttpMethodsClient $http
     */
    public function __construct(GithubWrapperInterface $github, HttpClient $http = null)
    {
        $this->github = $github;

        $this->http = $http ?: HttpClientDiscovery::find();
        if (!$this->http instanceof HttpMethodsClient) {
            $this->http = new HttpMethodsClient($this->http, MessageFactoryDiscovery::find());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function inspect($author, $name)
    {
        try {
            // Fetch api endpoints
            $args = [$author, $name];
            $repo = $this->github->api('repo/show', $args);
            $participation = $this->github->api('repo/participation', $args);

            // Fetch html to save API quota
            // Or we could sacrifice quota and make ~4 requestes instead of 1 request
            $htmlStats = $this->getHtmlStats($repo['html_url']);

            $commits = $htmlStats['commits'];
            $branches = $htmlStats['branches'];
            $releases = $htmlStats['releases'];
            $contributors = $htmlStats['contributors'];
        } catch (GithubAPIException $e) {
            throw new Exception\RepoInspectorAPIException(sprintf('Github API request failed; %s', $e->getMessage()), 0, $e);
        } catch (\Exception $e) {
            throw new Exception\RepoInspectorCrawlerException(sprintf('Github repo stats request failed; %s', $e->getMessage()), 0, $e);
        }

        $tdCreatedDays = (time() - strtotime($repo['created_at'])) / 86400;
        $tdPushedHours = (time() - strtotime($repo['pushed_at'])) / 3600;

        // Popularity score
        $popularity = (($repo['stargazers_count'] * self::R_POP_STARS_FACTOR)
                        + ($repo['subscribers_count'] * self::R_POP_SUBSCRIBERS_FACTOR)
                        + ($repo['forks_count'] * self::R_POP_FORKS_FACTOR)) / 8;

        // Hotness score
        $hot = $repo['stargazers_count']
                / pow($tdCreatedDays + self::R_HOT_DAYS, self::R_HOT_GRAVITY) * 10000;

        // Maturity score
        $maturity = ($commits * self::R_MATURITY_COMMITS_FACTOR)
                    + ($releases * self::R_MATURITY_RELEASES_FACTOR)
                    + ($contributors * self::R_MATURITY_CONTRIBS_FACTOR)
                    + (($repo['size'] / 1000) * self::R_MATURITY_SIZE_FACTOR);
        $maturity += sqrt($maturity) * 2 * ($tdCreatedDays / 30 / 12);

        // Activity score
        $partScore = 0;
        $partWeeks = 0;
        foreach ($participation['all'] as $partCommits) {
            if ($partCommits > 0) {
                $partScore += $partCommits / self::R_ACTIVITY_WEEK_MIN;
                ++$partWeeks;
            }
        }
        // weeks prior to repo creation
        $gift = 52 - ceil(min($tdCreatedDays / 7, 52));
        $giftValue = 0;
        if($partWeeks > 0){
            $giftValue = min($partScore/$partWeeks, 0.5);
            // Adjust the gift value for low activity repos
            if($partWeeks < 8){
                $giftValue = min($giftValue, 0.2);
            }
        }
        // optimal is 52*52 => 2704
        $activity = ($partScore + ($gift * $giftValue)) * ($partWeeks + $gift);
        // optimal here is 2929 (if the repo was pushed within the last 12 hours)
        $activity += $activity / max(12, $tdPushedHours);

        $scores = [
            // PHAM score
            'p' => (int) round($popularity),
            'h' => (int) round($hot),
            'a' => (int) round($activity),
            'm' => (int) round($maturity),
        ];

        $scores_avg = (int) round(array_sum($scores) / count($scores));

        return array_merge($this->cleanRepoResponse($repo), [
            'commits_count' => $commits,
            'branches_count' => $branches,
            'releases_count' => $releases,
            'contributers_count' => $contributors,
            'scores' => $scores,
            'scores_avg' => $scores_avg,
        ]);
    }

    /**
     * Fetchs some repo stats from the repo html page (to save API quota).
     *
     * @param $url
     *
     * @return array
     */
    protected function getHtmlStats($url)
    {
        try {
            $html = $this->http->get($url);
            $html = (string) $html->getBody();
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Unable too fecth repo page %s', $url));
        }

        try {
            $crawler = new DomCrawler\Crawler($html);
            $nums = [
                'commits' => $crawler->filter('.numbers-summary li:nth-child(1) .num')->text(),
                'branches' => $crawler->filter('.numbers-summary li:nth-child(2) .num')->text(),
                'releases' => $crawler->filter('.numbers-summary li:nth-child(3) .num')->text(),
                'contributors' => $crawler->filter('.numbers-summary li:nth-child(4) .num')->text(),
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Unable too parse repo page %s; %s', $url, $e->getMessage()));
        }

        $nums = array_map(function ($text) {
            return (int) preg_replace('/[^0-9]/', '', $text);
        }, $nums);

        return $nums;
    }

    /**
     * Cleans repo json data received from the API.
     *
     * @param array $json
     *
     * @return array
     */
    protected function cleanRepoResponse(array $json)
    {
        foreach ($json as $k => $v) {
            if (is_array($v)) {
                $json[$k] = $this->cleanRepoResponse($v);
            } else {
                if ($k === 'html_url') {
                    $json['url'] = $v;
                    unset($json[$k]);
                }
                if (false !== strpos($k, 'url') && !in_array($k, ['avatar_url'])) {
                    unset($json[$k]);
                }
            }
        }

        return $json;
    }
}
