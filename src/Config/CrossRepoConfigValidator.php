<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Config;

use DateTimeImmutable;
use Padosoft\PatentBoxTracker\Sources\Internal\GitProcess;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Strict validator for the cross-repo YAML config consumed by
 * `patent-box:cross-repo`.
 *
 * The validator enforces R30-style isolation invariants on the
 * cross-repo runner:
 *   - period_from < period_to (chronological order)
 *   - regime in the controlled allowlist
 *   - every role in {primary_ip, support, meta_self}
 *   - every repository path exists AND is a real git repo
 *   - at least one `primary_ip` repository
 *   - no unknown top-level keys (strict schema)
 *
 * Unknown nested keys under `tax_identity`, `cost_model`,
 * `classifier`, `repositories[*]`, `manual_supplement`, and
 * `ip_outputs[*]` are also rejected — typos in field names
 * (`p_iva` vs `piva`, `denomination` vs `name`) surface immediately
 * instead of silently dropping into nothing.
 */
final class CrossRepoConfigValidator
{
    private const ALLOWED_TOP_LEVEL = [
        'fiscal_year',
        'period',
        'tax_identity',
        'cost_model',
        'classifier',
        'repositories',
        'manual_supplement',
        'ip_outputs',
    ];

    private const REQUIRED_TOP_LEVEL = [
        'fiscal_year',
        'period',
        'tax_identity',
        'cost_model',
        'classifier',
        'repositories',
    ];

    private const ALLOWED_PERIOD_KEYS = ['from', 'to'];

    private const ALLOWED_TAX_IDENTITY_KEYS = ['denomination', 'p_iva', 'regime'];

    private const ALLOWED_COST_MODEL_KEYS = ['hourly_rate_eur', 'daily_hours_max'];

    private const ALLOWED_CLASSIFIER_KEYS = ['provider', 'model'];

    private const ALLOWED_REPO_KEYS = ['path', 'role'];

    private const ALLOWED_REGIMES = ['documentazione_idonea', 'non_documentazione'];

    private const ALLOWED_ROLES = ['primary_ip', 'support', 'meta_self'];

    /**
     * Read + parse + validate the YAML config at the given path.
     *
     * @throws CrossRepoConfigException on any read / parse / validation failure
     */
    public function validateFile(string $path): CrossRepoConfig
    {
        if (! is_file($path)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config file does not exist: "%s".',
                $path,
            ));
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new CrossRepoConfigException(sprintf(
                'Failed to read cross-repo config file: "%s".',
                $path,
            ));
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parse($raw);
        } catch (ParseException $exception) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config file "%s" is not valid YAML: %s',
                $path,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! is_array($parsed)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config file "%s" must decode to a YAML mapping; got %s.',
                $path,
                get_debug_type($parsed),
            ));
        }

        return $this->validate($parsed);
    }

    /**
     * Validate an already-parsed config array.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws CrossRepoConfigException
     */
    public function validate(array $config): CrossRepoConfig
    {
        $this->guardTopLevelKeys($config);

        $fiscalYear = $this->validateFiscalYear($config['fiscal_year'] ?? null);
        [$periodFrom, $periodTo] = $this->validatePeriod($config['period'] ?? null);
        $taxIdentity = $this->validateTaxIdentity($config['tax_identity'] ?? null);
        $costModel = $this->validateCostModel($config['cost_model'] ?? null);
        $classifier = $this->validateClassifier($config['classifier'] ?? null);
        $repositories = $this->validateRepositories($config['repositories'] ?? null);

        /** @var array<string, mixed> $manualSupplement */
        $manualSupplement = $this->normalizeOptionalArray('manual_supplement', $config['manual_supplement'] ?? null);
        /** @var list<array<string, mixed>> $ipOutputs */
        $ipOutputs = $this->validateIpOutputs($config['ip_outputs'] ?? null);

        return new CrossRepoConfig(
            fiscalYear: $fiscalYear,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            taxIdentity: $taxIdentity,
            costModel: $costModel,
            classifier: $classifier,
            repositories: $repositories,
            manualSupplement: $manualSupplement,
            ipOutputs: $ipOutputs,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function guardTopLevelKeys(array $config): void
    {
        foreach (self::REQUIRED_TOP_LEVEL as $required) {
            if (! array_key_exists($required, $config)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config is missing required top-level key "%s".',
                    $required,
                ));
            }
        }

        foreach ($config as $key => $_) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_TOP_LEVEL, true)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config has unknown top-level key "%s". Allowed: [%s].',
                    is_string($key) ? $key : (string) $key,
                    implode(', ', self::ALLOWED_TOP_LEVEL),
                ));
            }
        }
    }

    private function validateFiscalYear(mixed $raw): string
    {
        if ($raw === null) {
            throw new CrossRepoConfigException('Cross-repo config: fiscal_year is required.');
        }

        // YAML deserialises bare 4-digit years as integers; accept both.
        $value = is_int($raw) ? (string) $raw : $raw;

        if (! is_string($value) || ! preg_match('/^\d{4}$/', $value)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: fiscal_year must be a 4-digit year, got "%s".',
                is_scalar($raw) ? (string) $raw : get_debug_type($raw),
            ));
        }

        return $value;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function validatePeriod(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new CrossRepoConfigException(
                'Cross-repo config: period must be a mapping with `from` and `to` keys.'
            );
        }

        foreach ($raw as $key => $_) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_PERIOD_KEYS, true)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: period.%s is not a valid key. Allowed: [%s].',
                    is_string($key) ? $key : (string) $key,
                    implode(', ', self::ALLOWED_PERIOD_KEYS),
                ));
            }
        }

        if (! array_key_exists('from', $raw) || ! array_key_exists('to', $raw)) {
            throw new CrossRepoConfigException(
                'Cross-repo config: period must declare both `from` and `to` ISO-8601 dates.'
            );
        }

        $from = $this->parseIso8601Date('period.from', $raw['from']);
        $to = $this->parseIso8601Date('period.to', $raw['to']);

        if ($from >= $to) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: period.from (%s) must be strictly earlier than period.to (%s).',
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
            ));
        }

        return [$from, $to];
    }

    private function parseIso8601Date(string $field, mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTime(0, 0, 0);
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTime(0, 0, 0);
        }

        // Symfony YAML decodes unquoted ISO-8601 dates (e.g. `2026-01-01`)
        // as Unix-timestamp integers by default. Accept that form so YAML
        // configs with bare dates parse cleanly without forcing operators
        // to remember to quote every date field.
        if (is_int($value)) {
            $dt = (new DateTimeImmutable('@'.$value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->setTime(0, 0, 0);

            return $dt;
        }

        if (! is_string($value)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: %s must be an ISO-8601 date string, got %s.',
                $field,
                get_debug_type($value),
            ));
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: %s must match YYYY-MM-DD, got "%s".',
                $field,
                $value,
            ));
        }

        try {
            return new DateTimeImmutable($value.'T00:00:00Z');
        } catch (\Exception $exception) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: %s "%s" is not a valid date: %s',
                $field,
                $value,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    /**
     * @return array{denomination: string, p_iva: string, regime: string}
     */
    private function validateTaxIdentity(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new CrossRepoConfigException('Cross-repo config: tax_identity must be a mapping.');
        }

        foreach ($raw as $key => $_) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_TAX_IDENTITY_KEYS, true)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: tax_identity.%s is not a valid key. Allowed: [%s].',
                    is_string($key) ? $key : (string) $key,
                    implode(', ', self::ALLOWED_TAX_IDENTITY_KEYS),
                ));
            }
        }

        $denomination = $this->stringField('tax_identity.denomination', $raw['denomination'] ?? null);
        $pIva = $this->stringField('tax_identity.p_iva', $raw['p_iva'] ?? null);
        $regime = $this->stringField('tax_identity.regime', $raw['regime'] ?? null);

        if (! in_array($regime, self::ALLOWED_REGIMES, true)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: tax_identity.regime must be one of [%s], got "%s".',
                implode(', ', self::ALLOWED_REGIMES),
                $regime,
            ));
        }

        return [
            'denomination' => $denomination,
            'p_iva' => $pIva,
            'regime' => $regime,
        ];
    }

    /**
     * @return array{hourly_rate_eur: float, daily_hours_max: int}
     */
    private function validateCostModel(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new CrossRepoConfigException('Cross-repo config: cost_model must be a mapping.');
        }

        foreach ($raw as $key => $_) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_COST_MODEL_KEYS, true)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: cost_model.%s is not a valid key. Allowed: [%s].',
                    is_string($key) ? $key : (string) $key,
                    implode(', ', self::ALLOWED_COST_MODEL_KEYS),
                ));
            }
        }

        $rate = $this->numberField('cost_model.hourly_rate_eur', $raw['hourly_rate_eur'] ?? null);
        $hours = $this->numberField('cost_model.daily_hours_max', $raw['daily_hours_max'] ?? null);

        if ($rate < 0) {
            throw new CrossRepoConfigException(
                'Cross-repo config: cost_model.hourly_rate_eur must be non-negative.'
            );
        }

        if ($hours <= 0 || $hours > 24) {
            throw new CrossRepoConfigException(
                'Cross-repo config: cost_model.daily_hours_max must be in (0, 24].'
            );
        }

        return [
            'hourly_rate_eur' => (float) $rate,
            'daily_hours_max' => (int) $hours,
        ];
    }

    /**
     * @return array{provider: string, model: string}
     */
    private function validateClassifier(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new CrossRepoConfigException('Cross-repo config: classifier must be a mapping.');
        }

        foreach ($raw as $key => $_) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_CLASSIFIER_KEYS, true)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: classifier.%s is not a valid key. Allowed: [%s].',
                    is_string($key) ? $key : (string) $key,
                    implode(', ', self::ALLOWED_CLASSIFIER_KEYS),
                ));
            }
        }

        $provider = $this->stringField('classifier.provider', $raw['provider'] ?? null);
        $model = $this->stringField('classifier.model', $raw['model'] ?? null);

        return [
            'provider' => $provider,
            'model' => $model,
        ];
    }

    /**
     * @return list<RepoConfig>
     */
    private function validateRepositories(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new CrossRepoConfigException(
                'Cross-repo config: repositories must be a non-empty YAML sequence.'
            );
        }

        if ($raw === []) {
            throw new CrossRepoConfigException(
                'Cross-repo config: repositories must contain at least one entry.'
            );
        }

        $configs = [];
        $seenPaths = [];
        $hasPrimary = false;
        foreach ($raw as $idx => $entry) {
            if (! is_array($entry)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: repositories[%d] must be a mapping with `path` + `role`.',
                    $idx,
                ));
            }

            foreach ($entry as $key => $_) {
                if (! is_string($key) || ! in_array($key, self::ALLOWED_REPO_KEYS, true)) {
                    throw new CrossRepoConfigException(sprintf(
                        'Cross-repo config: repositories[%d].%s is not a valid key. Allowed: [%s].',
                        $idx,
                        is_string($key) ? $key : (string) $key,
                        implode(', ', self::ALLOWED_REPO_KEYS),
                    ));
                }
            }

            $path = $this->stringField(sprintf('repositories[%d].path', $idx), $entry['path'] ?? null);
            $role = $this->stringField(sprintf('repositories[%d].role', $idx), $entry['role'] ?? null);

            if (! in_array($role, self::ALLOWED_ROLES, true)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: repositories[%d].role must be one of [%s], got "%s".',
                    $idx,
                    implode(', ', self::ALLOWED_ROLES),
                    $role,
                ));
            }

            if (! is_dir($path)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: repositories[%d].path "%s" does not exist or is not a directory.',
                    $idx,
                    $path,
                ));
            }

            if (! GitProcess::isRepository($path)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: repositories[%d].path "%s" is not a git repository.',
                    $idx,
                    $path,
                ));
            }

            $canonicalPath = realpath($path);
            $key = $canonicalPath === false ? $path : $canonicalPath;
            if (isset($seenPaths[$key])) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: repositories[%d].path "%s" appears twice; each repo must be unique.',
                    $idx,
                    $path,
                ));
            }
            $seenPaths[$key] = true;

            if ($role === 'primary_ip') {
                $hasPrimary = true;
            }

            $configs[] = new RepoConfig(path: $path, role: $role);
        }

        if (! $hasPrimary) {
            throw new CrossRepoConfigException(
                'Cross-repo config: repositories must contain at least one entry with role "primary_ip".'
            );
        }

        return $configs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateIpOutputs(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new CrossRepoConfigException(
                'Cross-repo config: ip_outputs (when present) must be a YAML sequence.'
            );
        }

        $rows = [];
        foreach ($raw as $idx => $entry) {
            if (! is_array($entry)) {
                throw new CrossRepoConfigException(sprintf(
                    'Cross-repo config: ip_outputs[%d] must be a mapping.',
                    $idx,
                ));
            }
            /** @var array<string, mixed> $entry */
            $rows[] = $entry;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOptionalArray(string $field, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: %s (when present) must be a YAML mapping, got %s.',
                $field,
                get_debug_type($raw),
            ));
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    private function stringField(string $field, mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: %s must be a non-empty string, got %s.',
                $field,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private function numberField(string $field, mixed $value): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new CrossRepoConfigException(sprintf(
                'Cross-repo config: %s must be a number, got %s.',
                $field,
                get_debug_type($value),
            ));
        }

        return (float) $value;
    }
}
