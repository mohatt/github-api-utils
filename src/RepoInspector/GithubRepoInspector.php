<?php

namespace Github\Utils\RepoInspector;

use Http\Client\HttpClient;
use Http\Client\Common\HttpMethodsClient;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
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
    private const R_HOT_WEEKS = 1;
    private const R_HOT_GRAVITY = 1.0;
    private const R_POP_STARS_FACTOR = 1.5;
    private const R_POP_SUBSCRIBERS_FACTOR = 1.6;
    private const R_POP_FORKS_FACTOR = 1.7;
    private const R_MATURITY_COMMITS_FACTOR = 1.2;
    private const R_MATURITY_RELEASES_FACTOR = 1.8;
    private const R_MATURITY_CONTRIBS_FACTOR = 1.5;
    private const R_MATURITY_AGE_FACTOR = 1.0;
    private const R_ACTIVITY_WEEK_MIN = 15;

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
     * @param HttpClient|HttpMethodsClient|null $http
     */
    public function __construct(GithubWrapperInterface $github, HttpClient $http = null)
    {
        $this->github = $github;

        $this->http = $http ?: HttpClientDiscovery::find();
        if (!$this->http instanceof HttpMethodsClient) {
            $this->http = new HttpMethodsClient($this->http, Psr17FactoryDiscovery::findRequestFactory());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function inspect(string $author,string $name): array
    {
        try {
            // Fetch api endpoints
            $args = [$author, $name];
            $repo = $this->github->api('repo/show', $args);
            $participation = $this->github->api('repo/participation', $args);

            // Fetch html to save API quota
            // Or we could sacrifice quota and make ~4 requests instead of 1 request
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

        $stargazers = $repo['stargazers_count'];
        $subscribers = $repo['subscribers_count'];
        $forks = $repo['forks_count'];
        $sizeMb = $repo['size'] / 1000;
        $tdCreatedWeeks = (time() - strtotime($repo['created_at'])) / 604800;

        /*
         * Popularity score
         */
        $popularity = ((log($stargazers) * sqrt($stargazers) * 4 * self::R_POP_STARS_FACTOR)
                        + (log($subscribers) * sqrt($subscribers) * 4 * self::R_POP_SUBSCRIBERS_FACTOR)
                        + (log($forks) * sqrt($forks) * 4 * self::R_POP_FORKS_FACTOR));

        /*
         * Hotness score
         */
        $hot = $popularity / (($tdCreatedWeeks + self::R_HOT_WEEKS) ** self::R_HOT_GRAVITY) * 10;

        /*
         * Activity score
         */
        $partScore = 0;
        $partWeeks = 0;
        foreach ($participation['all'] as $partCommits) {
            if ($partCommits > 0) {
                $partScore += $partCommits / self::R_ACTIVITY_WEEK_MIN;
                ++$partWeeks;
            }
        }
        // Weeks prior to repo creation
        $gift = 52 - ceil(min($tdCreatedWeeks, 52));
        $giftValue = 0;
        if($partWeeks > 0){
            $giftValue = min($partScore/$partWeeks, 0.5);
            // Adjust the gift value for low activity repos
            if($partWeeks < 8){
                $giftValue = min($giftValue, 0.2);
            }
        }

        // Optimal is 52*52 => 2704
        $activity = ($partScore + ($gift * $giftValue)) * ($partWeeks + $gift);

        /*
         * Maturity score
         */
        $maturity = (log($commits) * sqrt($commits) * self::R_MATURITY_COMMITS_FACTOR)
            + ($releases * 10 * self::R_MATURITY_RELEASES_FACTOR)
            + ($contributors * 10 * self::R_MATURITY_CONTRIBS_FACTOR)
            + log10($releases+$contributors) * 500;
        $maturity += log($maturity) * ($maturity ** 0.35) * ($tdCreatedWeeks / 52) * self::R_MATURITY_AGE_FACTOR;
        // No need to consider the size factor, if the maturity score is already too low
        if ($maturity > 500) {
            // Since big size doesn't always mean better quality
            //  We will add its raw value in MB
            $maturity += $sizeMb;
            // Help low-sized repos (with relatively good maturity score) get better score
            // This value will increase as the maturity-size gap increases
            $maturity += log($maturity) * ($maturity / (max($sizeMb, 1) * 5));
        }

        // PHAM score
        $scores = [
            'p' => (int) ceil($popularity),
            'h' => (int) ceil($hot),
            'a' => (int) ceil($activity),
            'm' => (int) ceil($maturity),
        ];

        $scores_avg = (int) round(($scores['p'] + $scores['a'] + $scores['m']) / 3);

        $licence_id = $repo['license']['spdx_id'] ?? '';
        if ($licence_id && in_array(strtolower($licence_id), ['none', 'noassertion'])) {
            $licence_id = '';
        }

        return array_merge($this->stripResponseUrls($repo), [
            'licence_id' => $licence_id,
            'commits_count' => $commits,
            'branches_count' => $branches,
            'releases_count' => $releases,
            'contributors_count' => $contributors,
            'scores' => $scores,
            'scores_avg' => $scores_avg,
        ]);
    }

    /**
     * Fetches some repo stats from the repo html page (to save API quota).
     *
     * @param string $url
     *
     * @return array
     */
    protected function getHtmlStats(string $url): array
    {
        try {
            $html = $this->http->get($url);
            $html = (string) $html->getBody();
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Unable too fetch repo page %s', $url));
        }
        $stats = [
            // Not all repos have these fields set
            'releases' => '0',
            'contributors' => '0',
        ];
        try {
            $crawler = new DomCrawler\Crawler($html);
            $crawler->filter('#repo-content-pjax-container .Link--primary')->each(function ($node) use (&$stats) {
                $matches = [];
                $subject = trim($node->text());
                if (preg_match('/([\d,]+)\s+branch(?:es)?/i', $subject, $matches)) {
                    $stats['branches'] = $matches[1];
                } elseif (preg_match('/([\d,]+)\s+commits?/i', $subject, $matches)) {
                    $stats['commits'] = $matches[1];
                } elseif (preg_match('/releases\s+([\d,]+)/i', $subject, $matches)) {
                    $stats['releases'] = $matches[1];
                } elseif (preg_match('/contributors\s+([\d,]+)/i', $subject, $matches)) {
                    $stats['contributors'] = $matches[1];
                }
            });

            if (count($stats) < 4) {
                throw new \Exception('Unable to extract required fields with DomCrawler');
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Unable too parse repo page %s; %s', $url, $e->getMessage()));
        }

        return array_map(static function ($text) {
            return (int) preg_replace('/\D/', '', $text);
        }, $stats);
    }

    /**
     * Strips unneeded repo url fields received from the API.
     *
     * @param array $json
     *
     * @return array
     */
    protected function stripResponseUrls(array $json): array
    {
        foreach ($json as $k => $v) {
            if (is_array($v)) {
                $json[$k] = $this->stripResponseUrls($v);
            } else if ('html_url' === $k) {
                $json['url'] = $v;
                unset($json[$k]);
            } else if ('avatar_url' !== $k && false !== strpos($k, '_url')) {
                unset($json[$k]);
            }
        }

        return $json;
    }
}
