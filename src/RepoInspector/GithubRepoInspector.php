<?php

declare(strict_types=1);

namespace Github\Utils\RepoInspector;

use Github\Exception\ExceptionInterface as GithubAPIException;
use Github\Utils\GithubWrapperInterface;
use Http\Client\Common\HttpMethodsClient;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DomCrawler;

/**
 * Statistical analysis tool for GitHub repositories.
 */
class GithubRepoInspector implements GithubRepoInspectorInterface
{
    /**
     * Score calculation constants.
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

    protected HttpMethodsClient $http;

    public function __construct(protected GithubWrapperInterface $github, ClientInterface|HttpMethodsClient|null $http = null)
    {
        $this->http = $http ?: Psr18ClientDiscovery::find();
        if (!$this->http instanceof HttpMethodsClient) {
            $this->http = new HttpMethodsClient($this->http, Psr17FactoryDiscovery::findRequestFactory());
        }
    }

    public function inspect(string $author, string $name): array
    {
        try {
            // Fetch api endpoints
            $args = [$author, $name];
            $repo = $this->github->api('repo/show', $args);
            $participation = $this->github->api('repo/participation', $args);
            // var_dump($repo['updated_at']);
            // var_dump($repo['pushed_at']);

            // Fetch html to save API quota
            // Or we could sacrifice quota and make ~4 requests instead of 1 request
            $htmlStats = $this->getHtmlStats($repo['html_url']);

            $commits = $htmlStats['commits'];
            $branches = $htmlStats['branches'];
            $tags = $htmlStats['tags'];
            $releases = $htmlStats['releases'];
            $contributors = $htmlStats['contributors'];
            $languages = $htmlStats['languages'];
        } catch (GithubAPIException $e) {
            throw new Exception\RepoInspectorAPIException(\sprintf('Github API request failed; %s', $e->getMessage()), $e->getCode(), $e);
        } catch (\Exception $e) {
            throw new Exception\RepoInspectorCrawlerException(\sprintf('Github repo stats request failed; %s', $e->getMessage()), $e->getCode(), $e);
        }

        $stargazers = $repo['stargazers_count'];
        $subscribers = $repo['subscribers_count'];
        $forks = $repo['forks_count'];
        $sizeMb = $repo['size'] / 1000;
        $tdCreatedWeeks = (time() - strtotime($repo['created_at'])) / 604800;

        // Popularity score
        $popularity = ((log($stargazers) * sqrt($stargazers) * 4 * self::R_POP_STARS_FACTOR)
                        + (log($subscribers) * sqrt($subscribers) * 4 * self::R_POP_SUBSCRIBERS_FACTOR)
                        + (log($forks) * sqrt($forks) * 4 * self::R_POP_FORKS_FACTOR));

        // Hotness score
        $hot = $popularity / (($tdCreatedWeeks + self::R_HOT_WEEKS) ** self::R_HOT_GRAVITY) * 10;

        // Activity score
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
        if ($partWeeks > 0) {
            $giftValue = min($partScore / $partWeeks, 0.5);
            // Adjust the gift value for low activity repos
            if ($partWeeks < 8) {
                $giftValue = min($giftValue, 0.2);
            }
        }

        // Optimal is 52*52 => 2704
        $activity = ($partScore + ($gift * $giftValue)) * ($partWeeks + $gift);

        // Maturity score
        $maturity = (log($commits) * sqrt($commits) * self::R_MATURITY_COMMITS_FACTOR)
            + ($releases * 10 * self::R_MATURITY_RELEASES_FACTOR)
            + ($contributors * 10 * self::R_MATURITY_CONTRIBS_FACTOR)
            + log10($releases + $contributors) * 500;
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

        $license_id = $repo['license']['spdx_id'] ?? '';
        if ($license_id && \in_array(strtolower($license_id), ['none', 'noassertion'])) {
            $license_id = '';
        }

        return array_merge($this->stripResponseUrls($repo), [
            'license_id' => $license_id,
            'commits_count' => $commits,
            'branches_count' => $branches,
            'tags_count' => $tags,
            'releases_count' => $releases,
            'contributors_count' => $contributors,
            'languages' => $languages,
            'scores' => $scores,
            'scores_avg' => $scores_avg,
        ]);
    }

    /**
     * Fetches some repo stats from the repo html page (to save API quota).
     *
     * @return array<string, int>
     *
     * @throws \RuntimeException
     */
    protected function getHtmlStats(string $url): array
    {
        try {
            $html = $this->http->get($url);
            $html = (string) $html->getBody();
            $countHtml = $this->http->get($url.'/branch-and-tag-count');
            $countHtml = (string) $countHtml->getBody();
        } catch (\Exception $e) {
            throw new \RuntimeException(\sprintf('Unable to fetch repo page %s; %s', $url, $e->getMessage()), $e->getCode(), $e);
        }
        $stats = [
            // Not all repos have these fields set
            'releases' => '0',
            'contributors' => '0',
        ];

        try {
            // Extract commits, releases, contributors
            $crawler = new DomCrawler\Crawler($html);
            $crawler->filter('#repo-content-pjax-container a')->each(function ($node) use (&$stats): void {
                $matches = [];
                $subject = trim($node->text());
                if (preg_match('/([\d,]+)\s+commits?/i', $subject, $matches)) {
                    $stats['commits'] = $matches[1];
                } elseif (preg_match('/releases\s+([\d,]+)/i', $subject, $matches)) {
                    $stats['releases'] = $matches[1];
                } elseif (preg_match('/contributors\s+([\d,]+)/i', $subject, $matches)) {
                    $stats['contributors'] = $matches[1];
                }
            });

            // Extract branches/tags counts
            $countCrawler = new DomCrawler\Crawler($countHtml);
            $countCrawler->filter('a')->each(function ($node) use (&$stats): void {
                $matches = [];
                $subject = trim($node->text());
                if (preg_match('/([\d,]+)\s+branch(?:es)?/i', $subject, $matches)) {
                    $stats['branches'] = $matches[1];
                } elseif (preg_match('/([\d,]+)\s+tags?/i', $subject, $matches)) {
                    $stats['tags'] = $matches[1];
                }
            });

            if (\count($stats) < 5) {
                throw new \Exception('Unable to extract required fields with DomCrawler');
            }

            // Extract languages
            $languages = [];
            $languageSection = $crawler->filter('h2:contains("Languages")')->closest('div');
            if ($languageSection->count()) {
                $languageSection->filter('ul > li')->each(function ($li) use (&$languages): void {
                    if (preg_match('/([\p{L}+#\-\s]+)\s+([\d.]+)%/u', $li->text(), $m)) {
                        $languages[] = [
                            'name' => trim($m[1]),
                            'percent' => (float) $m[2],
                        ];
                    }
                });
            }
            // Add languages to stats
            $stats['languages'] = $languages;
        } catch (\Exception $e) {
            throw new \RuntimeException(\sprintf('Unable to parse repo page %s; %s', $url, $e->getMessage()), $e->getCode(), $e);
        }

        // Convert numeric fields while keeping languages as-is
        foreach ($stats as $key => &$value) {
            if ('languages' !== $key) {
                $value = (int) preg_replace('/\D/', '', (string) $value);
            }
        }

        return $stats;
    }

    /**
     * Strips unneeded repo url fields received from the API.
     */
    protected function stripResponseUrls(array $json): array
    {
        foreach ($json as $k => $v) {
            if (\is_array($v)) {
                $json[$k] = $this->stripResponseUrls($v);
            } elseif ('html_url' === $k) {
                $json['url'] = $v;
                unset($json[$k]);
            } elseif ('avatar_url' !== $k && str_contains((string) $k, '_url')) {
                unset($json[$k]);
            }
        }

        return $json;
    }
}
