<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test;

use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * @group slow
 */
class AllFunctionalTest extends TestCase
{
    /** @var string|false */
    protected $oldcwd;
    /** @var ?string */
    protected $testDir;
    /**
     * @var string
     */
    private static $pharPath;

    public function setUp(): void
    {
        $this->oldcwd = getcwd();

        chdir(__DIR__.'/Fixtures/functional');
    }

    public function tearDown(): void
    {
        if ($this->oldcwd) {
            chdir($this->oldcwd);
        }

        if ($this->testDir) {
            $fs = new Filesystem;
            $fs->removeDirectory($this->testDir);
            $this->testDir = null;
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::$pharPath = self::getUniqueTmpDirectory() . '/composer.phar';
    }

    public static function tearDownAfterClass(): void
    {
        $fs = new Filesystem;
        $fs->removeDirectory(dirname(self::$pharPath));
    }

    public function testBuildPhar()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Building the phar does not work on HHVM.');
        }

        $target = dirname(self::$pharPath);
        $fs = new Filesystem();
        chdir($target);

        $it = new \RecursiveDirectoryIterator(__DIR__.'/../../../', \RecursiveDirectoryIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($ri as $file) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $ri->getSubPathName();
            if ($file->isDir()) {
                $fs->ensureDirectoryExists($targetPath);
            } else {
                copy($file->getPathname(), $targetPath);
            }
        }

        // TODO in v2.3 always call with an array
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $proc = new Process(array((defined('PHP_BINARY') ? PHP_BINARY : 'php'), '-dphar.readonly=0', './bin/compile'), $target);
        } else {
            // @phpstan-ignore-next-line
            $proc = new Process((defined('PHP_BINARY') ? escapeshellcmd(PHP_BINARY) : 'php').' -dphar.readonly=0 '.escapeshellarg('./bin/compile'), $target);
        }
        $exitcode = $proc->run();

        if ($exitcode !== 0 || trim($proc->getOutput())) {
            $this->fail($proc->getOutput());
        }

        $this->assertFileExists(self::$pharPath);
    }

    /**
     * @dataProvider getTestFiles
     * @depends testBuildPhar
     * @param string $testFile
     */
    public function testIntegration($testFile)
    {
        $testData = $this->parseTestFile($testFile);
        $this->testDir = self::getUniqueTmpDirectory();

        // if a dir is present with the name of the .test file (without .test), we
        // copy all its contents in the $testDir to be used to run the test with
        $testFileSetupDir = substr($testFile, 0, -5);
        if (is_dir($testFileSetupDir)) {
            $fs = new Filesystem();
            $fs->copy($testFileSetupDir, $this->testDir);
        }

        $env = array(
            'COMPOSER_HOME' => $this->testDir.'home',
            'COMPOSER_CACHE_DIR' => $this->testDir.'cache',
        );

        // TODO in v2.3 always call with an array
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $cmd = array((defined('PHP_BINARY') ? PHP_BINARY : 'php'), self::$pharPath, '--no-ansi', $testData['RUN']);
            $proc = new Process($cmd, $this->testDir, $env, null, 300);
        } else {
            $cmd = (defined('PHP_BINARY') ? escapeshellcmd(PHP_BINARY) : 'php') .' '.escapeshellarg(self::$pharPath).' --no-ansi '.$testData['RUN'];
            // @phpstan-ignore-next-line
            $proc = new Process($cmd, $this->testDir, $env, null, 300);
        }
        $output = '';

        $exitcode = $proc->run(function ($type, $buffer) use (&$output) {
            $output .= $buffer;
        });

        if (isset($testData['EXPECT'])) {
            $output = trim($this->cleanOutput($output));
            $expected = $testData['EXPECT'];

            $line = 1;
            for ($i = 0, $j = 0; $i < strlen($expected);) {
                if ($expected[$i] === "\n") {
                    $line++;
                }
                if ($expected[$i] === '%') {
                    Preg::isMatch('{%(.+?)%}', substr($expected, $i), $match);
                    $regex = $match[1];

                    if (Preg::isMatch('{'.$regex.'}', substr($output, $j), $match)) {
                        $i += strlen($regex) + 2;
                        $j += strlen($match[0]);
                        continue;
                    } else {
                        $this->fail(
                            'Failed to match pattern '.$regex.' at line '.$line.' / abs offset '.$i.': '
                            .substr($output, $j, min(strpos($output, "\n", $j) - $j, 100)).PHP_EOL.PHP_EOL.
                            'Output:'.PHP_EOL.$output
                        );
                    }
                }
                if ($expected[$i] !== $output[$j]) {
                    $this->fail(
                        'Output does not match expectation at line '.$line.' / abs offset '.$i.': '.PHP_EOL
                        .'-'.substr($expected, $i, min(strpos($expected, "\n", $i) - $i, 100)).PHP_EOL
                        .'+'.substr($output, $j, min(strpos($output, "\n", $j) - $j, 100)).PHP_EOL.PHP_EOL
                        .'Output:'.PHP_EOL.$output
                    );
                }
                $i++;
                $j++;
            }
        }
        if (isset($testData['EXPECT-REGEX'])) {
            $this->assertRegExp($testData['EXPECT-REGEX'], $this->cleanOutput($output));
        }
        if (isset($testData['EXPECT-REGEXES'])) {
            $cleanOutput = $this->cleanOutput($output);
            foreach (explode("\n", $testData['EXPECT-REGEXES']) as $regex) {
                $this->assertRegExp($regex, $cleanOutput, 'Output: '.$output);
            }
        }
        if (isset($testData['EXPECT-EXIT-CODE'])) {
            $this->assertSame($testData['EXPECT-EXIT-CODE'], $exitcode);
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public function getTestFiles()
    {
        $tests = array();
        foreach (Finder::create()->in(__DIR__.'/Fixtures/functional')->name('*.test')->files() as $file) {
            $tests[basename($file)] = array($file->getRealPath());
        }

        return $tests;
    }

    /**
     * @param string $file
     * @return array<string, int|string>
     */
    private function parseTestFile($file)
    {
        $tokens = Preg::split('#(?:^|\n*)--([A-Z-]+)--\n#', file_get_contents($file), -1, PREG_SPLIT_DELIM_CAPTURE);
        $data = array();
        $section = null;

        foreach ($tokens as $token) {
            if ('' === $token && null === $section) {
                continue;
            }

            // Handle section headers.
            if (null === $section) {
                $section = $token;
                continue;
            }

            $sectionData = $token;

            // Allow sections to validate, or modify their section data.
            switch ($section) {
                case 'EXPECT-EXIT-CODE':
                    $sectionData = (int) $sectionData;
                    break;

                case 'RUN':
                case 'EXPECT':
                case 'EXPECT-REGEX':
                case 'EXPECT-REGEXES':
                    $sectionData = trim($sectionData);
                    break;

                case 'TEST':
                    break;

                default:
                    throw new \RuntimeException(sprintf(
                        'Unknown section "%s". Allowed sections: "RUN", "EXPECT", "EXPECT-EXIT-CODE", "EXPECT-REGEX", "EXPECT-REGEXES". '
                       .'Section headers must be written as "--HEADER_NAME--".',
                        $section
                    ));
            }

            $data[$section] = $sectionData;
            $section = $sectionData = null;
        }

        // validate data
        if (!isset($data['RUN'])) {
            throw new \RuntimeException('The test file must have a section named "RUN".');
        }
        if (!isset($data['EXPECT']) && !isset($data['EXPECT-REGEX']) && !isset($data['EXPECT-REGEXES'])) {
            throw new \RuntimeException('The test file must have a section named "EXPECT", "EXPECT-REGEX", or "EXPECT-REGEXES".');
        }

        return $data;
    }

    /**
     * @param string $output
     * @return string
     */
    private function cleanOutput($output)
    {
        $processed = '';

        for ($i = 0; $i < strlen($output); $i++) {
            if ($output[$i] === "\x08") {
                $processed = substr($processed, 0, -1);
            } elseif ($output[$i] !== "\r") {
                $processed .= $output[$i];
            }
        }

        return $processed;
    }
}
