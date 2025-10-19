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
 *
 * This inspector consolidates GitHub API and scraped HTML metadata into the PHAM
 * score bundle:
 *
 *  • Popularity benchmarks against 50k stars, 5k subscribers, 10k forks (values above those references exceed 1000).
 *  • Hotness mixes latest pushes, short-term commit momentum, and popularity; recent surges land around 1000, dramatic spikes can exceed it.
 *  • Activity compares annual commits (1.2k reference) and active weeks (52 reference).
 *  • Maturity weighs total commits (5k), releases (100), contributors (200), age (~4 years), and size (500 MB).
 *
 * Scores are left unbounded for relative sorting, but crossing the reference profile typically yields ≈1000.
 */
class GithubRepoInspector implements GithubRepoInspectorInterface
{
    /**
     * Score calibration constants.
     */
    private const POPULARITY_STAR_REF = 50000;
    private const POPULARITY_SUBSCRIBER_REF = 5000;
    private const POPULARITY_FORK_REF = 10000;

    private const HOT_RECENT_WEEKS = 4;
    private const HOT_PUSH_HALF_LIFE_WEEKS = 4;
    private const HOT_TREND_DECAY_WEEKS = 250;
    private const HOT_YOUTH_RAMP_WEEKS = 26;
    private const HOT_YOUTH_FLOOR = 0.35;
    private const HOT_POPULARITY_MOMENTUM_SCALE = 400;
    private const HOT_HIGHLIGHT_STAR_THRESHOLD = 400;

    private const ACTIVITY_ANNUAL_COMMITS_REF = 1200;

    private const MATURITY_COMMITS_REF = 5000;
    private const MATURITY_RELEASES_REF = 100;
    private const MATURITY_CONTRIBUTORS_REF = 200;
    private const MATURITY_AGE_REF_WEEKS = 52 * 4;
    private const MATURITY_SIZE_REF = 500;

    protected HttpMethodsClient $http;

    public function __construct(protected GithubWrapperInterface $github, ClientInterface|HttpMethodsClient|null $http = null)
    {
        $this->http = $http ?: Psr18ClientDiscovery::find();
        if (!$this->http instanceof HttpMethodsClient) {
            $this->http = new HttpMethodsClient($this->http, Psr17FactoryDiscovery::findRequestFactory());
        }
    }

    /**
     * Returns merged repository metadata and scoring metrics.
     *
     * The payload mirrors the GitHub repo JSON with URLs stripped plus:
     *  - scores.p/h/a/m : integer PHAM scores (unbounded, ~1000 marks the reference profile described in the class docs).
     *  - scores_avg     : integer average of popularity/activity/maturity.
     *  - highlight      : associative array with the leading metric (`type`), a concise narrative (`message`), and an optional maturity `component`.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $author, string $name): array
    {
        try {
            // Fetch api endpoints
            $args = [$author, $name];
            $repo = $this->github->api('repo/show', $args);
            $participation = $this->github->api('repo/participation', $args);

            // Fetch HTML to save API quota (Otherwise we'd make ~4 requests instead of 1 request)
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

        $now = time();
        $stargazers = (int) $repo['stargazers_count'];
        $subscribers = (int) $repo['subscribers_count'];
        $forks = (int) $repo['forks_count'];
        $sizeMb = $repo['size'] / 1000;

        $participationAll = [];
        if (isset($participation['all']) && \is_array($participation['all'])) {
            $participationAll = $participation['all'];
        }

        $tdCreatedWeeks = max(0, ($now - strtotime($repo['created_at'])) / 604800);
        $weeksSincePush = $this->weeksSince($repo['pushed_at'] ?? ($repo['updated_at'] ?? null), $now);
        $recentCommits = $this->sumRecentWeeks($participationAll, self::HOT_RECENT_WEEKS);
        $annualCommits = array_sum($participationAll);
        $activeWeeks = \count(array_filter($participationAll, static fn ($count): bool => (int) $count > 0));

        $popularityScore = 100 * (
            6.0 * $this->normalizeLogScale($stargazers, self::POPULARITY_STAR_REF)
            + 2.0 * $this->normalizeLogScale($subscribers, self::POPULARITY_SUBSCRIBER_REF)
            + 2.0 * $this->normalizeLogScale($forks, self::POPULARITY_FORK_REF)
        );

        $recencyScore = 0.5 ** ($weeksSincePush / self::HOT_PUSH_HALF_LIFE_WEEKS);
        $popularityMomentum = min(1.0, $popularityScore / max(self::HOT_POPULARITY_MOMENTUM_SCALE, 1));

        $averageWeeklyCommits = $annualCommits > 0 ? $annualCommits / 52 : 0;
        $baselineRecent = max(1.0, $averageWeeklyCommits * self::HOT_RECENT_WEEKS);
        $momentumRatio = $baselineRecent > 0 ? $recentCommits / $baselineRecent : 0;
        $momentumFactor = $momentumRatio > 0 ? log1p($momentumRatio) : 0;

        $agePenalty = 1 / (1 + ($tdCreatedWeeks / self::HOT_TREND_DECAY_WEEKS));
        $youthDamping = $this->youthDampingFactor($tdCreatedWeeks);
        $hotScore = 100 * (
            (1.5 * $recencyScore)
            + (1.5 * $momentumFactor)
            + (7.0 * $popularityMomentum)
        ) * $agePenalty * $youthDamping;

        $activityVolumeScore = $this->normalizePowerScale($annualCommits, self::ACTIVITY_ANNUAL_COMMITS_REF, 0.6);
        $consistencyScore = $this->normalizeLinearScale($activeWeeks, 52);
        $activityScore = 100 * (
            6.5 * $activityVolumeScore
            + 3.5 * $consistencyScore
        );

        $commitScore = $this->normalizePowerScale($commits, self::MATURITY_COMMITS_REF, 1.2, 3.5);
        $releaseScore = $this->normalizePowerScale($releases, self::MATURITY_RELEASES_REF, 1.1, 3.0);
        $contributorScore = $this->normalizePowerScale($contributors, self::MATURITY_CONTRIBUTORS_REF, 1.15, 3.0);
        $ageScore = $this->normalizeLogScale($tdCreatedWeeks, self::MATURITY_AGE_REF_WEEKS);
        $sizeScore = $this->normalizeSizeScore($sizeMb);
        $maturityScore = 100 * (
            3.5 * $commitScore
            + 2.5 * $contributorScore
            + 2.0 * $releaseScore
            + 1.5 * $ageScore
            + 0.5 * $sizeScore
        );

        $highlight = $this->buildHighlight([
            'scores' => [
                'popularity' => $popularityScore,
                'hotness' => $hotScore,
                'activity' => $activityScore,
                'maturity' => $maturityScore,
            ],
            'stargazers' => $stargazers,
            'subscribers' => $subscribers,
            'forks' => $forks,
            'recentCommits' => $recentCommits,
            'baselineRecent' => $baselineRecent,
            'weeksSincePush' => $weeksSincePush,
            'annualCommits' => $annualCommits,
            'activeWeeks' => $activeWeeks,
            'tdCreatedWeeks' => $tdCreatedWeeks,
            'commits' => $commits,
            'contributors' => $contributors,
            'releases' => $releases,
        ]);

        // PHAM score (Popularity, Hotness, Activity, Maturity)
        $scores = array_map(
            static fn (float $value): int => (int) round($value),
            [
                'p' => $popularityScore,
                'h' => $hotScore,
                'a' => $activityScore,
                'm' => $maturityScore,
            ]
        );

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
            'highlight' => $highlight,
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
            $languageSection = $crawler->filter('h2:contains("Languages")');
            if ($languageSection->count()) {
                $languageSection->closest('div')->filter('ul > li')->each(function ($li) use (&$languages): void {
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

    private function normalizeLogScale(float $value, float $reference): float
    {
        if ($value <= 0) {
            return 0;
        }

        if ($reference <= 0) {
            return log1p($value);
        }

        return log1p($value) / log1p($reference);
    }

    private function normalizeLinearScale(float $value, float $reference): float
    {
        if ($value <= 0) {
            return 0;
        }

        if ($reference <= 0) {
            return $value;
        }

        return $value / $reference;
    }

    private function normalizePowerScale(float $value, float $reference, float $exponent = 1.0, ?float $capRatio = null): float
    {
        if ($value <= 0.0) {
            return 0.0;
        }

        $reference = max($reference, 1.0);
        $ratio = $value / $reference;
        if (null !== $capRatio) {
            $ratio = min($ratio, $capRatio);
        }
        if ($ratio <= 0) {
            return 0;
        }

        return $ratio ** $exponent;
    }

    private function normalizeSizeScore(float $sizeMb): float
    {
        if ($sizeMb <= 0) {
            return 0;
        }

        $reference = max(self::MATURITY_SIZE_REF, 1.0);
        $ratio = $sizeMb / $reference;

        if ($ratio <= 1.0) {
            // Smaller projects earn a gentler ramp to avoid over-rewarding tiny repos.
            return $ratio ** 0.7;
        }

        // Cap at 1.0 to avoid over-rewarding large repos
        return 1.0;
    }

    /**
     * Dampens hotness score for very young repositories to avoid runaway spikes.
     */
    private function youthDampingFactor(float $ageWeeks): float
    {
        if ($ageWeeks <= 0.0) {
            return self::HOT_YOUTH_FLOOR;
        }

        $ramp = $ageWeeks / max(self::HOT_YOUTH_RAMP_WEEKS, 1);

        return min(1, max(self::HOT_YOUTH_FLOOR, $ramp));
    }

    /**
     * @param array{
     *     scores: array{popularity: float, hotness: float, activity: float, maturity: float},
     *     stargazers: int,
     *     subscribers: int,
     *     forks: int,
     *     recentCommits: int,
     *     baselineRecent: float,
     *     weeksSincePush: float,
     *     annualCommits: int,
     *     activeWeeks: int,
     *     tdCreatedWeeks: float,
     *     commits: int,
     *     contributors: int,
     *     releases: int
     * } $context
     *
     * @return array{type: string, message: string, component: ?string}
     */
    private function buildHighlight(array $context): array
    {
        $dimensionScores = $context['scores'];
        arsort($dimensionScores);

        foreach (array_keys($dimensionScores) as $dimension) {
            $highlight = match ($dimension) {
                'popularity' => $this->buildPopularityHighlight($context),
                'hotness' => $this->buildHotnessHighlight($context),
                'maturity' => $this->buildMaturityHighlight($context),
                default => $this->buildActivityHighlight($context),
            };

            if (null !== $highlight) {
                return $highlight;
            }
        }

        throw new \RuntimeException('Unable to build highlight');
    }

    /**
     * @param array{
     *     tdCreatedWeeks: float,
     *     commits: int,
     *     contributors: int,
     *     releases: int
     * } $context
     *
     * @return array{component: string, message: string}
     */
    private function selectMaturityHighlight(array $context): array
    {
        $ageLabel = $this->formatAge($context['tdCreatedWeeks']);

        $componentRatios = [
            'commits' => $context['commits'] / max(self::MATURITY_COMMITS_REF, 1),
            'contributors' => $context['contributors'] / max(self::MATURITY_CONTRIBUTORS_REF, 1),
            'releases' => $context['releases'] / max(self::MATURITY_RELEASES_REF, 1),
        ];

        arsort($componentRatios);
        $topComponent = (string) key($componentRatios);

        switch ($topComponent) {
            case 'contributors':
                $count = $this->formatCompactNumber($context['contributors']);
                $message = \sprintf('Deep bench • %s contributors • %s', $count, $ageLabel);

                break;

            case 'releases':
                $count = $this->formatCompactNumber($context['releases']);
                $message = \sprintf('Release machine • %s releases • %s', $count, $ageLabel);

                break;

            case 'commits':
            default:
                $count = $this->formatCompactNumber($context['commits']);
                $message = \sprintf('Seasoned code • %s commits • %s', $count, $ageLabel);
                $topComponent = 'commits';

                break;
        }

        return [
            'component' => $topComponent,
            'message' => $message,
        ];
    }

    /**
     * @param array{
     *     stargazers: int
     * } $context
     *
     * @return array{type: string, message: string, component: ?string}
     */
    private function buildPopularityHighlight(array $context): array
    {
        $stars = $this->formatCompactNumber($context['stargazers']);

        return [
            'type' => 'popularity',
            'message' => \sprintf('Star magnet • %s stars', $stars),
            'component' => null,
        ];
    }

    /**
     * @param array{
     *     scores: array{popularity: float, hotness: float, activity: float, maturity: float},
     *     recentCommits: int,
     *     baselineRecent: float,
     *     weeksSincePush: float,
     *     stargazers: int,
     *     tdCreatedWeeks: float
     * } $context
     *
     * @return null|array{type: string, message: string, component: ?string}
     */
    private function buildHotnessHighlight(array $context): ?array
    {
        $recentCommitsCount = max(0, (int) $context['recentCommits']);
        $recentCommits = $this->formatCompactNumber($recentCommitsCount);
        $commitLabel = 1 === $recentCommitsCount ? 'commit' : 'commits';
        $weeks = self::HOT_RECENT_WEEKS;
        $weekLabel = 'weeks';
        $paceRatio = $context['baselineRecent'] > 0 ? $context['recentCommits'] / $context['baselineRecent'] : 0;
        $starsCount = max(0, $context['stargazers']);
        $stars = $this->formatCompactNumber($starsCount);
        $starLabel = 'stars';
        $popScore = $context['scores']['popularity'];
        $hotScore = $context['scores']['hotness'];
        $ageWeeks = max(0, $context['tdCreatedWeeks']);
        $ageLabel = $this->formatAge($ageWeeks);
        $isYoung = $ageWeeks > 0 && $ageWeeks <= 52;
        $recentPush = $context['weeksSincePush'] <= 1;
        $hasStars = $starsCount >= self::HOT_HIGHLIGHT_STAR_THRESHOLD;

        $shouldSkipHighlight = !$hasStars
            && !$recentPush
            && $paceRatio < 1.2
            && $recentCommitsCount <= $weeks;

        if ($shouldSkipHighlight) {
            return null;
        }

        $message = match (true) {
            $hasStars && $isYoung => \sprintf('Fast climb • %s %s in %s', $stars, $starLabel, $ageLabel),
            $recentPush && $hasStars => \sprintf('Fresh buzz • %s %s + recent push', $stars, $starLabel),
            $recentPush => \sprintf('Fresh buzz • %s %s over %d %s', $recentCommits, $commitLabel, $weeks, $weekLabel),
            $paceRatio >= 1.5 && $hasStars => \sprintf('Momentum spike • %s %s & %sx commits', $stars, $starLabel, $this->formatDecimal($paceRatio)),
            $paceRatio >= 1.5 => \sprintf('Momentum spike • %sx commits', $this->formatDecimal($paceRatio)),
            $popScore >= ($hotScore * 0.85) && $hasStars => \sprintf('Hype wave • %s %s • %s', $stars, $starLabel, $ageLabel),
            $hasStars => \sprintf('Heat check • %s %s + %s %s', $stars, $starLabel, $recentCommits, $commitLabel),
            default => \sprintf('%s %s over %d %s', $recentCommits, $commitLabel, $weeks, $weekLabel),
        };

        return [
            'type' => 'hotness',
            'message' => $message,
            'component' => null,
        ];
    }

    /**
     * @param array{
     *     annualCommits: int,
     *     activeWeeks: int
     * } $context
     *
     * @return array{type: string, message: string, component: ?string}
     */
    private function buildActivityHighlight(array $context): array
    {
        $activeWeeks = max(0, $context['activeWeeks']);
        $weeklyAverage = $context['annualCommits'] > 0 ? $context['annualCommits'] / 52 : 0;

        return [
            'type' => 'activity',
            'message' => \sprintf(
                'Steady cadence • %d/52w active • %s per week',
                $activeWeeks,
                $this->formatDecimal($weeklyAverage)
            ),
            'component' => null,
        ];
    }

    /**
     * @param array{
     *     tdCreatedWeeks: float,
     *     commits: int,
     *     contributors: int,
     *     releases: int
     * } $context
     *
     * @return array{type: string, message: string, component: ?string}
     */
    private function buildMaturityHighlight(array $context): array
    {
        $maturityComponent = $this->selectMaturityHighlight($context);

        return [
            'type' => 'maturity',
            'message' => $maturityComponent['message'],
            'component' => $maturityComponent['component'],
        ];
    }

    private function formatCompactNumber(int $value): string
    {
        if ($value >= 1000000) {
            return $this->trimTrailingZeros(\sprintf('%.1f', $value / 1000000)).'m';
        }

        if ($value >= 1000) {
            return $this->trimTrailingZeros(\sprintf('%.1f', $value / 1000)).'k';
        }

        return (string) $value;
    }

    private function formatDecimal(float $value, int $precision = 1): string
    {
        if ($value <= 0.0) {
            return '0';
        }

        return $this->trimTrailingZeros(\sprintf('%.'.$precision.'f', $value));
    }

    private function trimTrailingZeros(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    private function formatAge(float $weeks): string
    {
        if ($weeks <= 0) {
            return 'less than a week';
        }

        $years = $weeks / 52;
        if ($years >= 5) {
            return \sprintf('%d years', (int) round($years));
        }

        if ($years >= 2) {
            return \sprintf('%s years', $this->formatDecimal($years));
        }

        if ($years >= 1) {
            $months = (int) round($years * 12);

            return 1 === $months ? '1 month' : \sprintf('%d months', $months);
        }

        if ($weeks >= 8) {
            $months = (int) round($weeks / 4.345);
            $months = max(1, $months);

            return 1 === $months ? '1 month' : \sprintf('%d months', $months);
        }

        $weeksRounded = (int) round($weeks);

        $weeksRounded = max(1, $weeksRounded);

        return 1 === $weeksRounded ? '1 week' : \sprintf('%d weeks', $weeksRounded);
    }

    private function weeksSince(?string $date, int $now): float
    {
        if (!$date) {
            return 52;
        }

        $timestamp = strtotime($date);
        if (false === $timestamp) {
            return 52;
        }

        return max(0, ($now - $timestamp) / 604800);
    }

    /**
     * @param array<int, int|string> $participation
     */
    private function sumRecentWeeks(array $participation, int $window): int
    {
        if ($window <= 0 || empty($participation)) {
            return 0;
        }

        $recent = \array_slice($participation, -$window);

        return (int) array_sum(array_map('intval', $recent));
    }
}
