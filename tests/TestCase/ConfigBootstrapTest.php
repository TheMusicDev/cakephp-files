<?php
declare(strict_types=1);

namespace TheMusicDev\Files\Test\TestCase;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * The plugin config convention: ship-with defaults sit UNDER whatever the host wrote; the host always wins.
 */
final class ConfigBootstrapTest extends TestCase
{
    private string $root;

    /**
     * @var array<string, mixed>
     */
    private array $saved = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = (array)Configure::read('Files');
        $this->root = sys_get_temp_dir() . '/files-config-test-' . bin2hex(random_bytes(4));
        Configure::delete('Files');
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        Configure::write('Files', $this->saved);
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
        parent::tearDown();
    }

    /**
     * @return void
     */
    private function runPluginBootstrap(): void
    {
        require dirname(__DIR__, 2) . '/config/bootstrap.php';
    }

    /**
     * A host that only chose a storage root, so the bootstrap does not create the plugin's own default root.
     *
     * @return void
     */
    private function hostWithRoot(): void
    {
        Configure::write('Files.backends', [
            'local' => ['class' => LocalFilesystemAdapter::class, 'root' => $this->root],
        ]);
    }

    public function testDefaultsFillWhatTheHostLeftOut(): void
    {
        $this->hostWithRoot();

        $this->runPluginBootstrap();

        $this->assertSame('local', Configure::read('Files.backend'));
        $this->assertSame('Downloads', Configure::read('Files.collections')['uploads.misc']['label']);
        $this->assertNull(Configure::read('Files.authCallable'));
    }

    public function testHostValuesWin(): void
    {
        $this->hostWithRoot();
        Configure::write('Files.collections', ['contracts.pdf' => ['label' => 'Contracts']]);
        $gate = static fn() => null;
        Configure::write('Files.authCallable', $gate);

        $this->runPluginBootstrap();

        $this->assertSame($gate, Configure::read('Files.authCallable'));
        $this->assertSame('Contracts', Configure::read('Files.collections')['contracts.pdf']['label']);
        // The built-in collection is still there beside the host's own.
        $this->assertArrayHasKey('uploads.misc', Configure::read('Files.collections'));
        $this->assertSame($this->root, Configure::read('Files.backends.local.root'));
    }

    public function testAHostCanReplaceTheBuiltInCollection(): void
    {
        $this->hostWithRoot();
        Configure::write('Files.collections', ['uploads.misc' => ['label' => 'Mine', 'size' => 10]]);

        $this->runPluginBootstrap();

        $this->assertSame('Mine', Configure::read('Files.collections')['uploads.misc']['label']);
        $this->assertSame(10, Configure::read('Files.collections')['uploads.misc']['size']);
    }

    public function testTheLocalStorageRootIsCreatedOnFirstRun(): void
    {
        $this->hostWithRoot();
        $this->assertDirectoryDoesNotExist($this->root);

        $this->runPluginBootstrap();

        $this->assertDirectoryExists($this->root);
    }
}
