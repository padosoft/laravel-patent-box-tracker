<?php

declare(strict_types=1);

namespace Padosoft\PatentBoxTracker\Tests\Unit\Config;

use Padosoft\PatentBoxTracker\Config\CrossRepoConfig;
use Padosoft\PatentBoxTracker\Config\CrossRepoConfigException;
use Padosoft\PatentBoxTracker\Config\CrossRepoConfigValidator;
use Padosoft\PatentBoxTracker\Tests\TestCase;

final class CrossRepoConfigValidatorTest extends TestCase
{
    private const FIXTURE_REPO = __DIR__.'/../../fixtures/repos/synthetic-r-and-d.git';

    private CrossRepoConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_dir(self::FIXTURE_REPO)) {
            $this->markTestSkipped(
                'Synthetic git fixture not built. Run tests/fixtures/repos/build-synthetic.sh first.'
            );
        }
        $this->validator = new CrossRepoConfigValidator;
    }

    /**
     * @return array<string, mixed>
     */
    private function happyConfig(): array
    {
        return [
            'fiscal_year' => '2026',
            'period' => [
                'from' => '2026-01-01',
                'to' => '2026-12-31',
            ],
            'tax_identity' => [
                'denomination' => 'Padosoft di Lorenzo Padovani',
                'p_iva' => 'IT00000000000',
                'regime' => 'documentazione_idonea',
            ],
            'cost_model' => [
                'hourly_rate_eur' => 80,
                'daily_hours_max' => 8,
            ],
            'classifier' => [
                'provider' => 'regolo',
                'model' => 'claude-sonnet-4-6',
            ],
            'repositories' => [
                ['path' => self::FIXTURE_REPO, 'role' => 'primary_ip'],
            ],
            'manual_supplement' => [
                'off_keyboard_research_hours' => 60,
            ],
            'ip_outputs' => [
                ['kind' => 'software_siae', 'title' => 'AskMyDocs v4.0', 'registration_id' => 'SIAE-2026-...'],
            ],
        ];
    }

    public function test_happy_path_produces_typed_dto(): void
    {
        $result = $this->validator->validate($this->happyConfig());

        $this->assertInstanceOf(CrossRepoConfig::class, $result);
        $this->assertSame('2026', $result->fiscalYear);
        $this->assertSame('2026-01-01', $result->periodFrom->format('Y-m-d'));
        $this->assertSame('2026-12-31', $result->periodTo->format('Y-m-d'));
        $this->assertSame('Padosoft di Lorenzo Padovani', $result->taxIdentity['denomination']);
        $this->assertSame('regolo', $result->classifier['provider']);
        $this->assertSame(1, $result->repositoryCount());
        $this->assertSame('primary_ip', $result->repositories[0]->role);
        $this->assertSame(60, $result->manualSupplement['off_keyboard_research_hours']);
        $this->assertCount(1, $result->ipOutputs);
    }

    public function test_rejects_missing_top_level_required_key(): void
    {
        $config = $this->happyConfig();
        unset($config['fiscal_year']);

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('missing required top-level key "fiscal_year"');

        $this->validator->validate($config);
    }

    public function test_rejects_unknown_top_level_key(): void
    {
        $config = $this->happyConfig();
        $config['mystery_field'] = 'oops';

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('unknown top-level key "mystery_field"');

        $this->validator->validate($config);
    }

    public function test_rejects_invalid_fiscal_year_format(): void
    {
        $config = $this->happyConfig();
        $config['fiscal_year'] = '26';

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('fiscal_year must be a 4-digit year');

        $this->validator->validate($config);
    }

    public function test_rejects_inverted_period(): void
    {
        $config = $this->happyConfig();
        $config['period'] = ['from' => '2026-12-31', 'to' => '2026-01-01'];

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('period.from');

        $this->validator->validate($config);
    }

    public function test_rejects_period_with_unknown_subkey(): void
    {
        $config = $this->happyConfig();
        $config['period'] = ['from' => '2026-01-01', 'to' => '2026-12-31', 'extra' => 'no'];

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('period.extra');

        $this->validator->validate($config);
    }

    public function test_rejects_unknown_regime(): void
    {
        $config = $this->happyConfig();
        $config['tax_identity']['regime'] = 'made_up_regime';

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('regime must be one of');

        $this->validator->validate($config);
    }

    public function test_rejects_repository_with_non_existent_path(): void
    {
        $config = $this->happyConfig();
        $config['repositories'][] = [
            'path' => '/this/path/definitely/does/not/exist/9988776',
            'role' => 'support',
        ];

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('does not exist');

        $this->validator->validate($config);
    }

    public function test_rejects_repository_with_non_git_path(): void
    {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'patent-box-non-git-'.uniqid();
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        try {
            $config = $this->happyConfig();
            $config['repositories'][] = [
                'path' => $tempDir,
                'role' => 'support',
            ];

            $this->expectException(CrossRepoConfigException::class);
            $this->expectExceptionMessage('is not a git repository');

            $this->validator->validate($config);
        } finally {
            @rmdir($tempDir);
        }
    }

    public function test_rejects_repository_with_unknown_role(): void
    {
        $config = $this->happyConfig();
        $config['repositories'][0]['role'] = 'lead_dev';

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('repositories[0].role');

        $this->validator->validate($config);
    }

    public function test_rejects_no_primary_ip_repository(): void
    {
        $config = $this->happyConfig();
        $config['repositories'][0]['role'] = 'support';

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('at least one entry with role "primary_ip"');

        $this->validator->validate($config);
    }

    public function test_rejects_duplicate_repository_paths(): void
    {
        $config = $this->happyConfig();
        $config['repositories'][] = ['path' => self::FIXTURE_REPO, 'role' => 'support'];

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('appears twice');

        $this->validator->validate($config);
    }

    public function test_rejects_unknown_repo_subkey(): void
    {
        $config = $this->happyConfig();
        $config['repositories'][0]['extra'] = 'no';

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('repositories[0].extra');

        $this->validator->validate($config);
    }

    public function test_rejects_invalid_cost_model_hours(): void
    {
        $config = $this->happyConfig();
        $config['cost_model']['daily_hours_max'] = 30;

        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('daily_hours_max must be in (0, 24]');

        $this->validator->validate($config);
    }

    public function test_validate_file_rejects_missing_path(): void
    {
        $this->expectException(CrossRepoConfigException::class);
        $this->expectExceptionMessage('does not exist');

        $this->validator->validateFile('/does-not-exist-path-9999.yml');
    }

    public function test_validate_file_round_trips_yaml(): void
    {
        $yaml = "fiscal_year: 2026\n";
        $yaml .= "period:\n  from: 2026-01-01\n  to: 2026-12-31\n";
        $yaml .= "tax_identity:\n  denomination: Padosoft di Lorenzo Padovani\n  p_iva: IT00000000000\n  regime: documentazione_idonea\n";
        $yaml .= "cost_model:\n  hourly_rate_eur: 80\n  daily_hours_max: 8\n";
        $yaml .= "classifier:\n  provider: regolo\n  model: claude-sonnet-4-6\n";
        $yaml .= "repositories:\n  - path: ".self::FIXTURE_REPO."\n    role: primary_ip\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'cross-repo-validator-');
        file_put_contents($tempFile, $yaml);
        try {
            $result = $this->validator->validateFile($tempFile);
            $this->assertSame('2026', $result->fiscalYear);
            $this->assertSame(1, $result->repositoryCount());
        } finally {
            @unlink($tempFile);
        }
    }
}
