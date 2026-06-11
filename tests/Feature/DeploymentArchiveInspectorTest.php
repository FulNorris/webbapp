<?php

namespace Tests\Feature;

use App\Services\DeploymentArchiveInspector;
use Tests\TestCase;

class DeploymentArchiveInspectorTest extends TestCase
{
    public function test_blocks_known_sensitive_and_runtime_paths(): void
    {
        $inspector = new DeploymentArchiveInspector();

        $this->assertSame('blocked_exact', $inspector->violationFor('.env'));
        $this->assertSame('blocked_prefix:.git/', $inspector->violationFor('.git/config'));
        $this->assertSame('blocked_prefix:node_modules/', $inspector->violationFor('node_modules/vue/package.json'));
        $this->assertSame('blocked_prefix:gips-image-venv/', $inspector->violationFor('gips-image-venv/bin/python'));
        $this->assertSame('blocked_prefix:gips-import-venv/', $inspector->violationFor('gips-import-venv/bin/python'));
        $this->assertSame('blocked_prefix:storage/app/backups/', $inspector->violationFor('storage/app/backups/dump.sql'));
        $this->assertSame('blocked_suffix:.sql', $inspector->violationFor('database/prod.sql'));
    }

    public function test_repository_profile_blocks_build_outputs_not_allowed_in_source(): void
    {
        $inspector = new DeploymentArchiveInspector();

        $this->assertSame('blocked_prefix:vendor/', $inspector->violationFor('vendor/autoload.php', 'repository'));
        $this->assertSame('blocked_prefix:public/build/', $inspector->violationFor('public/build/manifest.json', 'repository'));
    }

    public function test_release_profile_allows_ci_built_runtime_dependencies(): void
    {
        $inspector = new DeploymentArchiveInspector();

        $this->assertTrue($inspector->isAllowed('vendor/autoload.php', 'release'));
        $this->assertTrue($inspector->isAllowed('public/build/manifest.json', 'release'));
    }

    public function test_allows_source_files_expected_in_repository(): void
    {
        $inspector = new DeploymentArchiveInspector();

        $this->assertTrue($inspector->isAllowed('app/Http/Controllers/InternalAppController.php'));
        $this->assertTrue($inspector->isAllowed('composer.lock'));
        $this->assertTrue($inspector->isAllowed('.env.example'));
        $this->assertTrue($inspector->isAllowed('public/index.php'));
        $this->assertTrue($inspector->isAllowed('storage/logs/.gitkeep'));
        $this->assertTrue($inspector->isAllowed('bootstrap/cache/.gitkeep'));
    }
}
