<?php

declare(strict_types=1);

/*
 * This file is part of the humbug/php-scoper package.
 *
 * Copyright (c) 2017 Théo FIDRY <theo.fidry@gmail.com>,
 *                    Pádraic Brady <padraic.brady@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Humbug\PhpScoper\Handler;

use Error;
use Humbug\PhpScoper\Logger\ConsoleLogger;
use Humbug\PhpScoper\Scoper;
use Humbug\PhpScoper\Throwable\Exception\ParsingException;
use Humbug\PhpScoper\Throwable\Exception\RuntimeException;
use PhpParser\Error as PhpParserError;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Throwable;
use function Humbug\PhpScoper\escape_path;
use function Humbug\PhpScoper\make_tmp_dir;
use function Humbug\PhpScoper\remove_dir;

/**
 * @covers \Humbug\PhpScoper\Handler\HandleAddPrefix
 */
class HandleAddPrefixTest extends TestCase
{
    const FIXTURE_PATH_000 = __DIR__.'/../../fixtures/set000';
    const FIXTURE_PATH_001 = __DIR__.'/../../fixtures/set001';

    /**
     * @var Scoper|ObjectProphecy
     */
    private $scoperProphecy;

    /**
     * @var ConsoleLogger|ObjectProphecy
     */
    private $loggerProphecy;

    /**
     * @var HandleAddPrefix
     */
    private $handle;

    /**
     * @var string
     */
    private $cwd;

    /**
     * @var string
     */
    private $tmp = '';

    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        if (file_exists($this->tmp)) {
            return;
        }

        $this->cwd = getcwd();
        $this->tmp = make_tmp_dir('scoper', __CLASS__);

        $this->scoperProphecy = $this->prophesize(Scoper::class);
        /** @var Scoper $scoper */
        $scoper = $this->scoperProphecy->reveal();

        $this->handle = new HandleAddPrefix($scoper);

        $this->loggerProphecy = $this->prophesize(ConsoleLogger::class);
    }

    /**
     * @inheritdoc
     */
    protected function tearDown()
    {
        chdir($this->cwd);

        remove_dir($this->tmp);
    }

    /**
     * @dataProvider providePaths
     */
    public function test_scopes_all_the_files_found_in_the_given_paths(array $paths, array $expected)
    {
        $prefix = 'MyPrefix';

        $paths = array_map(
            function (string $relativePath) {
                return realpath(escape_path(self::FIXTURE_PATH_000.'/'.$relativePath));
            },
            $paths
        );

        $outputPath = $this->tmp;

        /** @var ConsoleLogger $logger */
        $logger = $this->loggerProphecy->reveal();

        foreach ($expected as $fileContent) {
            $filePath = realpath(escape_path(self::FIXTURE_PATH_000.$fileContent));
            $this->assertNotFalse($filePath, 'Type check.');

            $this->scoperProphecy->scope($filePath, $prefix)->shouldBeCalled();

            $this->loggerProphecy->outputSuccess($filePath)->shouldBeCalled();
        }

        $this->loggerProphecy->outputFileCount(count($expected))->shouldBeCalled();

        $this->handle->__invoke($prefix, $paths, $outputPath, $logger);

        $this->scoperProphecy->scope(Argument::cetera())->shouldHaveBeenCalledTimes(count($expected));

        $this->loggerProphecy->outputSuccess(Argument::cetera())->shouldHaveBeenCalledTimes(count($expected));
        $this->loggerProphecy->outputFileCount(Argument::cetera())->shouldHaveBeenCalledTimes(1);
    }

    public function test_replaces_the_content_of_the_files_with_the_scoped_content()
    {
        $prefix = 'MyPrefix';

        $paths = [
            $filePath = escape_path(self::FIXTURE_PATH_001.'/file.php'),
        ];

        $outputPath = $this->tmp;

        /** @var ConsoleLogger $logger */
        $logger = $this->loggerProphecy->reveal();

        $expected = <<<PHP
<?php

declare(strict_types=1);

namespace Myprefix\MyNamespace;

PHP;

        $this->scoperProphecy->scope(Argument::any(), $prefix)->willReturn($expected);

        $this->handle->__invoke($prefix, $paths, $outputPath, $logger);

        $actual = file_get_contents(
            escape_path($this->tmp.'/file.php')
        );

        $this->assertSame($expected, $actual);
    }

    public function test_cannot_collect_files_from_unknown_paths()
    {
        $prefix = 'MyPrefix';

        $paths = [
            $filePath = escape_path('/nowhere'),
        ];

        $outputPath = $this->tmp.DIRECTORY_SEPARATOR.'output-dir';

        /** @var ConsoleLogger $logger */
        $logger = $this->loggerProphecy->reveal();

        $this->scoperProphecy->scope(Argument::cetera())->shouldNotBeCalled();

        try {
            $this->handle->__invoke($prefix, $paths, $outputPath, $logger);

            $this->fail('Expected exception to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Could not find the file "/nowhere".',
                $exception->getMessage()
            );
            $this->assertSame(0, $exception->getCode());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertFalse(file_exists($outputPath));
    }

    public function test_removes_scoped_files_on_failure()
    {
        $prefix = 'MyPrefix';

        $paths = [
            $filePath = escape_path(self::FIXTURE_PATH_001.'/file.php'),
        ];

        $outputPath = $this->tmp.DIRECTORY_SEPARATOR.'output-dir';

        /** @var ConsoleLogger $logger */
        $logger = $this->loggerProphecy->reveal();

        $this->scoperProphecy
            ->scope(Argument::any(), $prefix)
            ->willThrow($error = new Error('Unknown error'))
        ;

        try {
            $this->handle->__invoke($prefix, $paths, $outputPath, $logger);

            $this->fail('Expected exception to be thrown.');
        } catch (Throwable $throwable) {
            $this->assertSame($error, $throwable);
        }

        $this->assertFalse(file_exists($outputPath));
    }

    public function test_throws_an_error_when_cannot_parse_a_file()
    {
        $prefix = 'MyPrefix';

        $paths = [
            $filePath = escape_path(self::FIXTURE_PATH_001.'/file.php'),
        ];

        $outputPath = $this->tmp.DIRECTORY_SEPARATOR.'output-dir';

        /** @var ConsoleLogger $logger */
        $logger = $this->loggerProphecy->reveal();

        $this->scoperProphecy
            ->scope(Argument::any(), $prefix)
            ->willThrow($error = new PhpParserError('Could not parse file'))
        ;

        try {
            $this->handle->__invoke($prefix, $paths, $outputPath, $logger);

            $this->fail('Expected exception to be thrown.');
        } catch (ParsingException $exception) {
            $this->assertSame(
                'Could not parse the file "'.realpath($filePath).'".',
                $exception->getMessage()
            );
            $this->assertEquals(0, $exception->getCode());
            $this->assertSame($error, $exception->getPrevious());
        }

        $this->assertFalse(file_exists($outputPath));
    }

    public function providePaths()
    {
        yield 'directory with file' => [
            [
                'dir1',
            ],
            [
                '/dir1/fileA.php',
            ],
        ];

        yield 'PHP file' => [
            [
                'file1.php',
            ],
            [
                '/file1.php',
            ],
        ];

        yield 'non PHP file' => [
            [
                'unknown',
            ],
            [
                '/unknown',
            ],
        ];

        yield 'empty directory' => [
            [
                'empty-dir',
            ],
            [],
        ];

        yield 'complete sample' => [
            [
                'dir1',
                'dir2',
                'file2.php',
            ],
            [
                '/dir1/fileA.php',
                '/dir2/dir3/fileD.php',
                '/dir2/dir3/unknown',
                '/dir2/fileB.php',
                '/dir2/fileC.php',
                '/dir2/unknown',
                '/file2.php',
            ],
        ];
    }
}
