<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\TextUI\Configuration;

use PHPUnit\Framework\Exception;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\TextUI\Configuration\Logging\CodeCoverage\Clover;
use PHPUnit\TextUI\Configuration\Logging\CodeCoverage\Crap4j;
use PHPUnit\TextUI\Configuration\Logging\CodeCoverage\Html as CodeCoverageHtml;
use PHPUnit\TextUI\Configuration\Logging\CodeCoverage\Php as CodeCoveragePhp;
use PHPUnit\TextUI\Configuration\Logging\CodeCoverage\Text as CodeCoverageText;
use PHPUnit\TextUI\Configuration\Logging\CodeCoverage\Xml as CodeCoverageXml;
use PHPUnit\TextUI\Configuration\Logging\Junit;
use PHPUnit\TextUI\Configuration\Logging\Logging;
use PHPUnit\TextUI\Configuration\Logging\PlainText;
use PHPUnit\TextUI\Configuration\Logging\TeamCity;
use PHPUnit\TextUI\Configuration\Logging\TestDox\Html as TestDoxHtml;
use PHPUnit\TextUI\Configuration\Logging\TestDox\Text as TestDoxText;
use PHPUnit\TextUI\Configuration\Logging\TestDox\Xml as TestDoxXml;
use PHPUnit\TextUI\DefaultResultPrinter;
use PHPUnit\Util\TestDox\CliTestDoxPrinter;
use PHPUnit\Util\Xml;
use SebastianBergmann\FileIterator\Facade as FileIteratorFacade;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Configuration
{
    /**
     * @var self[]
     */
    private static $instances = [];

    /**
     * @var \DOMDocument
     */
    private $document;

    /**
     * @var \DOMXPath
     */
    private $xpath;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var \LibXMLError[]
     */
    private $errors = [];

    /**
     * Returns a PHPUnit configuration object.
     *
     * @throws Exception
     */
    public static function getInstance(string $filename): self
    {
        $realPath = \realpath($filename);

        if ($realPath === false) {
            throw new Exception(
                \sprintf(
                    'Could not read "%s".',
                    $filename
                )
            );
        }

        if (!isset(self::$instances[$realPath])) {
            self::$instances[$realPath] = new self($realPath);
        }

        return self::$instances[$realPath];
    }

    /**
     * Loads a PHPUnit configuration file.
     *
     * @throws Exception
     */
    private function __construct(string $filename)
    {
        $this->filename = $filename;
        $this->document = Xml::loadFile($filename, false, true, true);
        $this->xpath    = new \DOMXPath($this->document);

        $this->validateConfigurationAgainstSchema();
    }

    /**
     * @codeCoverageIgnore
     */
    private function __clone()
    {
    }

    public function hasValidationErrors(): bool
    {
        return \count($this->errors) > 0;
    }

    public function getValidationErrors(): array
    {
        $result = [];

        foreach ($this->errors as $error) {
            if (!isset($result[$error->line])) {
                $result[$error->line] = [];
            }

            $result[$error->line][] = \trim($error->message);
        }

        return $result;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getExtensionConfiguration(): ExtensionCollection
    {
        $extensions = [];

        foreach ($this->xpath->query('extensions/extension') as $extension) {
            $extensions[] = $this->getElementConfigurationParameters($extension);
        }

        return ExtensionCollection::fromArray($extensions);
    }

    public function getFilterConfiguration(): Filter
    {
        $addUncoveredFilesFromWhitelist     = true;
        $processUncoveredFilesFromWhitelist = false;

        $nodes = $this->xpath->query('filter/whitelist');

        if ($nodes->length === 1) {
            $node = $nodes->item(0);

            \assert($node instanceof \DOMElement);

            if ($node->hasAttribute('addUncoveredFilesFromWhitelist')) {
                $addUncoveredFilesFromWhitelist = (bool) $this->getBoolean(
                    (string) $node->getAttribute('addUncoveredFilesFromWhitelist'),
                    true
                );
            }

            if ($node->hasAttribute('processUncoveredFilesFromWhitelist')) {
                $processUncoveredFilesFromWhitelist = (bool) $this->getBoolean(
                    (string) $node->getAttribute('processUncoveredFilesFromWhitelist'),
                    false
                );
            }
        }

        return new Filter(
            $this->readFilterDirectories('filter/whitelist/directory'),
            $this->readFilterFiles('filter/whitelist/file'),
            $this->readFilterDirectories('filter/whitelist/exclude/directory'),
            $this->readFilterFiles('filter/whitelist/exclude/file'),
            $addUncoveredFilesFromWhitelist,
            $processUncoveredFilesFromWhitelist
        );
    }

    public function getGroupConfiguration(): Groups
    {
        return $this->parseGroupConfiguration('groups');
    }

    public function getTestdoxGroupConfiguration(): Groups
    {
        return $this->parseGroupConfiguration('testdoxGroups');
    }

    public function getListenerConfiguration(): ExtensionCollection
    {
        $listeners = [];

        foreach ($this->xpath->query('listeners/listener') as $listener) {
            $listeners[] = $this->getElementConfigurationParameters($listener);
        }

        return ExtensionCollection::fromArray($listeners);
    }

    public function getLoggingConfiguration(): Logging
    {
        $codeCoverageClover = null;
        $codeCoverageCrap4j = null;
        $codeCoverageHtml   = null;
        $codeCoveragePhp    = null;
        $codeCoverageText   = null;
        $codeCoverageXml    = null;
        $junit              = null;
        $plainText          = null;
        $teamCity           = null;
        $testDoxHtml        = null;
        $testDoxText        = null;
        $testDoxXml         = null;

        foreach ($this->xpath->query('logging/log') as $log) {
            \assert($log instanceof \DOMElement);

            $type   = (string) $log->getAttribute('type');
            $target = (string) $log->getAttribute('target');

            if (!$target) {
                continue;
            }

            $target = $this->toAbsolutePath($target);

            switch ($type) {
                case 'coverage-clover':
                    $codeCoverageClover = new Clover(
                        new File($target)
                    );

                    break;

                case 'coverage-crap4j':
                    $codeCoverageCrap4j = new Crap4j(
                        new File($target),
                        $this->getIntegerAttribute($log, 'threshold', 30)
                    );

                    break;

                case 'coverage-html':
                    $codeCoverageHtml = new CodeCoverageHtml(
                        new Directory($target),
                        $this->getIntegerAttribute($log, 'lowUpperBound', 50),
                        $this->getIntegerAttribute($log, 'highLowerBound', 90)
                    );

                    break;

                case 'coverage-php':
                    $codeCoveragePhp = new CodeCoveragePhp(
                        new File($target)
                    );

                    break;

                case 'coverage-text':
                    $codeCoverageText = new CodeCoverageText(
                        new File($target),
                        $this->getBooleanAttribute($log, 'showUncoveredFiles', false),
                        $this->getBooleanAttribute($log, 'showOnlySummary', false)
                    );

                    break;

                case 'coverage-xml':
                    $codeCoverageXml = new CodeCoverageXml(
                        new Directory($target)
                    );

                    break;

                case 'plain':
                    $plainText = new PlainText(
                        new File($target)
                    );

                    break;

                case 'junit':
                    $junit = new Junit(
                        new File($target)
                    );

                    break;

                case 'teamcity':
                    $teamCity = new TeamCity(
                        new File($target)
                    );

                    break;

                case 'testdox-html':
                    $testDoxHtml = new TestDoxHtml(
                        new File($target)
                    );

                    break;

                case 'testdox-text':
                    $testDoxText = new TestDoxText(
                        new File($target)
                    );

                    break;

                case 'testdox-xml':
                    $testDoxXml = new TestDoxXml(
                        new File($target)
                    );

                    break;
            }
        }

        return new Logging(
            $codeCoverageClover,
            $codeCoverageCrap4j,
            $codeCoverageHtml,
            $codeCoveragePhp,
            $codeCoverageText,
            $codeCoverageXml,
            $junit,
            $plainText,
            $teamCity,
            $testDoxHtml,
            $testDoxText,
            $testDoxXml
        );
    }

    public function getPHPConfiguration(): Php
    {
        $includePaths = [];

        foreach ($this->xpath->query('php/includePath') as $includePath) {
            $path = (string) $includePath->textContent;

            if ($path) {
                $includePaths[] = new Directory($this->toAbsolutePath($path));
            }
        }

        $iniSettings = [];

        foreach ($this->xpath->query('php/ini') as $ini) {
            \assert($ini instanceof \DOMElement);

            $iniSettings[] = new IniSetting(
                (string) $ini->getAttribute('name'),
                (string) $ini->getAttribute('value')
            );
        }

        $constants = [];

        foreach ($this->xpath->query('php/const') as $const) {
            \assert($const instanceof \DOMElement);

            $value = (string) $const->getAttribute('value');

            $constants[] = new Constant(
                (string) $const->getAttribute('name'),
                $this->getBoolean($value, $value)
            );
        }

        $variables = [
            'var'     => [],
            'env'     => [],
            'post'    => [],
            'get'     => [],
            'cookie'  => [],
            'server'  => [],
            'files'   => [],
            'request' => [],
        ];

        foreach (['var', 'env', 'post', 'get', 'cookie', 'server', 'files', 'request'] as $array) {
            foreach ($this->xpath->query('php/' . $array) as $var) {
                \assert($var instanceof \DOMElement);

                $name     = (string) $var->getAttribute('name');
                $value    = (string) $var->getAttribute('value');
                $force    = false;
                $verbatim = false;

                if ($var->hasAttribute('force')) {
                    $force = (bool) $this->getBoolean($var->getAttribute('force'), false);
                }

                if ($var->hasAttribute('verbatim')) {
                    $verbatim = $this->getBoolean($var->getAttribute('verbatim'), false);
                }

                if (!$verbatim) {
                    $value = $this->getBoolean($value, $value);
                }

                $variables[$array][] = new Variable($name, $value, $force);
            }
        }

        return new Php(
            DirectoryCollection::fromArray($includePaths),
            IniSettingCollection::fromArray($iniSettings),
            ConstantCollection::fromArray($constants),
            VariableCollection::fromArray($variables['var']),
            VariableCollection::fromArray($variables['env']),
            VariableCollection::fromArray($variables['post']),
            VariableCollection::fromArray($variables['get']),
            VariableCollection::fromArray($variables['cookie']),
            VariableCollection::fromArray($variables['server']),
            VariableCollection::fromArray($variables['files']),
            VariableCollection::fromArray($variables['request']),
        );
    }

    public function getPHPUnitConfiguration(): PHPUnit
    {
        $executionOrder      = TestSuiteSorter::ORDER_DEFAULT;
        $defectsFirst        = false;
        $resolveDependencies = $this->getBooleanAttribute($this->document->documentElement, 'resolveDependencies', true);

        if ($this->document->documentElement->hasAttribute('executionOrder')) {
            foreach (\explode(',', $this->document->documentElement->getAttribute('executionOrder')) as $order) {
                switch ($order) {
                    case 'default':
                        $executionOrder      = TestSuiteSorter::ORDER_DEFAULT;
                        $defectsFirst        = false;
                        $resolveDependencies = true;

                        break;

                    case 'depends':
                        $resolveDependencies = true;

                        break;

                    case 'no-depends':
                        $resolveDependencies = false;

                        break;

                    case 'defects':
                        $defectsFirst = true;

                        break;

                    case 'duration':
                        $executionOrder = TestSuiteSorter::ORDER_DURATION;

                        break;

                    case 'random':
                        $executionOrder = TestSuiteSorter::ORDER_RANDOMIZED;

                        break;

                    case 'reverse':
                        $executionOrder = TestSuiteSorter::ORDER_REVERSED;

                        break;

                    case 'size':
                        $executionOrder = TestSuiteSorter::ORDER_SIZE;

                        break;
                }
            }
        }

        $printerClass                          = $this->getStringAttribute($this->document->documentElement, 'printerClass');
        $testdox                               = $this->getBooleanAttribute($this->document->documentElement, 'testdox', false);
        $conflictBetweenPrinterClassAndTestdox = false;

        if ($testdox) {
            if ($printerClass !== null) {
                $conflictBetweenPrinterClassAndTestdox = true;
            }

            $printerClass = CliTestDoxPrinter::class;
        }

        $cacheResultFile = $this->getStringAttribute($this->document->documentElement, 'cacheResultFile');

        if ($cacheResultFile !== null) {
            $cacheResultFile = $this->toAbsolutePath($cacheResultFile);
        }

        $bootstrap = $this->getStringAttribute($this->document->documentElement, 'bootstrap');

        if ($bootstrap !== null) {
            $bootstrap = $this->toAbsolutePath($bootstrap);
        }

        $extensionsDirectory = $this->getStringAttribute($this->document->documentElement, 'extensionsDirectory');

        if ($extensionsDirectory !== null) {
            $extensionsDirectory = $this->toAbsolutePath($extensionsDirectory);
        }

        $testSuiteLoaderFile = $this->getStringAttribute($this->document->documentElement, 'testSuiteLoaderFile');

        if ($testSuiteLoaderFile !== null) {
            $testSuiteLoaderFile = $this->toAbsolutePath($testSuiteLoaderFile);
        }

        $printerFile = $this->getStringAttribute($this->document->documentElement, 'printerFile');

        if ($printerFile !== null) {
            $printerFile = $this->toAbsolutePath($printerFile);
        }

        return new PHPUnit(
            $this->getBooleanAttribute($this->document->documentElement, 'cacheResult', false),
            $cacheResultFile,
            $this->getBooleanAttribute($this->document->documentElement, 'cacheTokens', false),
            $this->getColumns(),
            $this->getColors(),
            $this->getBooleanAttribute($this->document->documentElement, 'stderr', false),
            $this->getBooleanAttribute($this->document->documentElement, 'noInteraction', false),
            $this->getBooleanAttribute($this->document->documentElement, 'verbose', false),
            $this->getBooleanAttribute($this->document->documentElement, 'reverseDefectList', false),
            $this->getBooleanAttribute($this->document->documentElement, 'convertDeprecationsToExceptions', true),
            $this->getBooleanAttribute($this->document->documentElement, 'convertErrorsToExceptions', true),
            $this->getBooleanAttribute($this->document->documentElement, 'convertNoticesToExceptions', true),
            $this->getBooleanAttribute($this->document->documentElement, 'convertWarningsToExceptions', true),
            $this->getBooleanAttribute($this->document->documentElement, 'forceCoversAnnotation', false),
            $this->getBooleanAttribute($this->document->documentElement, 'ignoreDeprecatedCodeUnitsFromCodeCoverage', false),
            $this->getBooleanAttribute($this->document->documentElement, 'disableCodeCoverageIgnore', false),
            $bootstrap,
            $this->getBooleanAttribute($this->document->documentElement, 'processIsolation', false),
            $this->getBooleanAttribute($this->document->documentElement, 'failOnWarning', false),
            $this->getBooleanAttribute($this->document->documentElement, 'failOnRisky', false),
            $this->getBooleanAttribute($this->document->documentElement, 'stopOnDefect', false),
            $this->getBooleanAttribute($this->document->documentElement, 'stopOnError', false),
            $this->getBooleanAttribute($this->document->documentElement, 'stopOnFailure', false),
            $this->getBooleanAttribute($this->document->documentElement, 'stopOnWarning', false),
            $this->getBooleanAttribute($this->document->documentElement, 'stopOnIncomplete', false),
            $this->getBooleanAttribute($this->document->documentElement, 'stopOnRisky', false),
            $this->getBooleanAttribute($this->document->documentElement, 'stopOnSkipped', false),
            $extensionsDirectory,
            $this->getStringAttribute($this->document->documentElement, 'testSuiteLoaderClass'),
            $testSuiteLoaderFile,
            $printerClass,
            $printerFile,
            $this->getBooleanAttribute($this->document->documentElement, 'beStrictAboutChangesToGlobalState', false),
            $this->getBooleanAttribute($this->document->documentElement, 'beStrictAboutOutputDuringTests', false),
            $this->getBooleanAttribute($this->document->documentElement, 'beStrictAboutResourceUsageDuringSmallTests', false),
            $this->getBooleanAttribute($this->document->documentElement, 'beStrictAboutTestsThatDoNotTestAnything', true),
            $this->getBooleanAttribute($this->document->documentElement, 'beStrictAboutTodoAnnotatedTests', false),
            $this->getBooleanAttribute($this->document->documentElement, 'beStrictAboutCoversAnnotation', false),
            $this->getBooleanAttribute($this->document->documentElement, 'enforceTimeLimit', false),
            $this->getIntegerAttribute($this->document->documentElement, 'defaultTimeLimit', 1),
            $this->getIntegerAttribute($this->document->documentElement, 'timeoutForSmallTests', 1),
            $this->getIntegerAttribute($this->document->documentElement, 'timeoutForMediumTests', 10),
            $this->getIntegerAttribute($this->document->documentElement, 'timeoutForLargeTests', 60),
            $this->getStringAttribute($this->document->documentElement, 'defaultTestSuite'),
            $executionOrder,
            $resolveDependencies,
            $defectsFirst,
            $this->getBooleanAttribute($this->document->documentElement, 'backupGlobals', false),
            $this->getBooleanAttribute($this->document->documentElement, 'backupStaticAttributes', false),
            $this->getBooleanAttribute($this->document->documentElement, 'registerMockObjectsFromTestArgumentsRecursively', false),
            $conflictBetweenPrinterClassAndTestdox
        );
    }

    public function getTestSuiteConfiguration(string $testSuiteFilter = ''): TestSuite
    {
        $testSuiteNodes = $this->xpath->query('testsuites/testsuite');

        if ($testSuiteNodes->length === 0) {
            $testSuiteNodes = $this->xpath->query('testsuite');
        }

        if ($testSuiteNodes->length === 1) {
            $element = $testSuiteNodes->item(0);

            \assert($element instanceof \DOMElement);

            return $this->getTestSuite($element, $testSuiteFilter);
        }

        $suite = new TestSuite;

        foreach ($testSuiteNodes as $testSuiteNode) {
            $suite->addTestSuite(
                $this->getTestSuite($testSuiteNode, $testSuiteFilter)
            );
        }

        return $suite;
    }

    public function getTestSuiteNames(): array
    {
        $names = [];

        foreach ($this->xpath->query('*/testsuite') as $node) {
            \assert($node instanceof \DOMElement);

            $names[] = $node->getAttribute('name');
        }

        return $names;
    }

    private function validateConfigurationAgainstSchema(): void
    {
        $original    = \libxml_use_internal_errors(true);
        $xsdFilename = __DIR__ . '/../../../phpunit.xsd';

        if (\defined('__PHPUNIT_PHAR_ROOT__')) {
            $xsdFilename =  __PHPUNIT_PHAR_ROOT__ . '/phpunit.xsd';
        }

        $this->document->schemaValidate($xsdFilename);
        $this->errors = \libxml_get_errors();
        \libxml_clear_errors();
        \libxml_use_internal_errors($original);
    }

    private function getConfigurationArguments(\DOMNodeList $nodes): array
    {
        $arguments = [];

        if ($nodes->length === 0) {
            return $arguments;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            if ($node->tagName !== 'arguments') {
                continue;
            }

            foreach ($node->childNodes as $argument) {
                if (!$argument instanceof \DOMElement) {
                    continue;
                }

                if ($argument->tagName === 'file' || $argument->tagName === 'directory') {
                    $arguments[] = $this->toAbsolutePath((string) $argument->textContent);
                } else {
                    $arguments[] = Xml::xmlToVariable($argument);
                }
            }
        }

        return $arguments;
    }

    private function getTestSuite(\DOMElement $testSuiteNode, string $testSuiteFilter = ''): TestSuite
    {
        if ($testSuiteNode->hasAttribute('name')) {
            $suite = new TestSuite(
                (string) $testSuiteNode->getAttribute('name')
            );
        } else {
            $suite = new TestSuite;
        }

        $exclude = [];

        foreach ($testSuiteNode->getElementsByTagName('exclude') as $excludeNode) {
            $excludeFile = (string) $excludeNode->textContent;

            if ($excludeFile) {
                $exclude[] = $this->toAbsolutePath($excludeFile);
            }
        }

        $fileIteratorFacade     = new FileIteratorFacade;
        $testSuiteFilterAsArray = $testSuiteFilter ? \explode(',', $testSuiteFilter) : [];

        foreach ($testSuiteNode->getElementsByTagName('directory') as $directoryNode) {
            \assert($directoryNode instanceof \DOMElement);

            if (!empty($testSuiteFilterAsArray) && !\in_array($directoryNode->parentNode->getAttribute('name'), $testSuiteFilterAsArray, true)) {
                continue;
            }

            $directory = (string) $directoryNode->textContent;

            if (empty($directory)) {
                continue;
            }

            if (!$this->satisfiesPhpVersion($directoryNode)) {
                continue;
            }

            $files = $fileIteratorFacade->getFilesAsArray(
                $this->toAbsolutePath($directory),
                $directoryNode->hasAttribute('suffix') ? (string) $directoryNode->getAttribute('suffix') : 'Test.php',
                $directoryNode->hasAttribute('prefix') ? (string) $directoryNode->getAttribute('prefix') : '',
                $exclude
            );

            $suite->addTestFiles($files);
        }

        foreach ($testSuiteNode->getElementsByTagName('file') as $fileNode) {
            \assert($fileNode instanceof \DOMElement);

            if (!empty($testSuiteFilterAsArray) && !\in_array($fileNode->parentNode->getAttribute('name'), $testSuiteFilterAsArray, true)) {
                continue;
            }

            $file = (string) $fileNode->textContent;

            if (empty($file)) {
                continue;
            }

            $file = $fileIteratorFacade->getFilesAsArray(
                $this->toAbsolutePath($file)
            );

            if (!isset($file[0])) {
                continue;
            }

            $file = $file[0];

            if (!$this->satisfiesPhpVersion($fileNode)) {
                continue;
            }

            $suite->addTestFile($file);
        }

        return $suite;
    }

    private function satisfiesPhpVersion(\DOMElement $node): bool
    {
        $phpVersion         = \PHP_VERSION;
        $phpVersionOperator = '>=';

        if ($node->hasAttribute('phpVersion')) {
            $phpVersion = (string) $node->getAttribute('phpVersion');
        }

        if ($node->hasAttribute('phpVersionOperator')) {
            $phpVersionOperator = (string) $node->getAttribute('phpVersionOperator');
        }

        return (bool) \version_compare(\PHP_VERSION, $phpVersion, $phpVersionOperator);
    }

    private function getBooleanAttribute(\DOMElement $element, string $attribute, bool $default): bool
    {
        if (!$element->hasAttribute($attribute)) {
            return $default;
        }

        return (bool) $this->getBoolean(
            (string) $element->getAttribute($attribute),
            false
        );
    }

    private function getIntegerAttribute(\DOMElement $element, string $attribute, int $default): int
    {
        if (!$element->hasAttribute($attribute)) {
            return $default;
        }

        return $this->getInteger(
            (string) $element->getAttribute($attribute),
            $default
        );
    }

    private function getStringAttribute(\DOMElement $element, string $attribute): ?string
    {
        if (!$element->hasAttribute($attribute)) {
            return null;
        }

        return (string) $element->getAttribute($attribute);
    }

    /**
     * if $value is 'false' or 'true', this returns the value that $value represents.
     * Otherwise, returns $default, which may be a string in rare cases.
     * See PHPUnit\TextUI\ConfigurationTest::testPHPConfigurationIsReadCorrectly
     *
     * @param bool|string $default
     *
     * @return bool|string
     */
    private function getBoolean(string $value, $default)
    {
        if (\strtolower($value) === 'false') {
            return false;
        }

        if (\strtolower($value) === 'true') {
            return true;
        }

        return $default;
    }

    private function getInteger(string $value, int $default): int
    {
        if (\is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function readFilterDirectories(string $query): FilterDirectoryCollection
    {
        $directories = [];

        foreach ($this->xpath->query($query) as $directoryNode) {
            \assert($directoryNode instanceof \DOMElement);

            $directoryPath = (string) $directoryNode->textContent;

            if (!$directoryPath) {
                continue;
            }

            $directories[] = new FilterDirectory(
                $this->toAbsolutePath($directoryPath),
                $directoryNode->hasAttribute('prefix') ? (string) $directoryNode->getAttribute('prefix') : '',
                $directoryNode->hasAttribute('suffix') ? (string) $directoryNode->getAttribute('suffix') : '.php',
                $directoryNode->hasAttribute('group') ? (string) $directoryNode->getAttribute('group') : 'DEFAULT'
            );
        }

        return FilterDirectoryCollection::fromArray($directories);
    }

    private function readFilterFiles(string $query): FilterFileCollection
    {
        $files = [];

        foreach ($this->xpath->query($query) as $file) {
            $filePath = (string) $file->textContent;

            if ($filePath) {
                $files[] = new FilterFile($this->toAbsolutePath($filePath));
            }
        }

        return FilterFileCollection::fromArray($files);
    }

    private function toAbsolutePath(string $path, bool $useIncludePath = false): string
    {
        $path = \trim($path);

        if (\strpos($path, '/') === 0) {
            return $path;
        }

        // Matches the following on Windows:
        //  - \\NetworkComputer\Path
        //  - \\.\D:
        //  - \\.\c:
        //  - C:\Windows
        //  - C:\windows
        //  - C:/windows
        //  - c:/windows
        if (\defined('PHP_WINDOWS_VERSION_BUILD') &&
            ($path[0] === '\\' || (\strlen($path) >= 3 && \preg_match('#^[A-Z]\:[/\\\]#i', \substr($path, 0, 3))))) {
            return $path;
        }

        if (\strpos($path, '://') !== false) {
            return $path;
        }

        $file = \dirname($this->filename) . \DIRECTORY_SEPARATOR . $path;

        if ($useIncludePath && !\file_exists($file)) {
            $includePathFile = \stream_resolve_include_path($path);

            if ($includePathFile) {
                $file = $includePathFile;
            }
        }

        return $file;
    }

    private function parseGroupConfiguration(string $root): Groups
    {
        $include = [];
        $exclude = [];

        foreach ($this->xpath->query($root . '/include/group') as $group) {
            $include[] = new Group((string) $group->textContent);
        }

        foreach ($this->xpath->query($root . '/exclude/group') as $group) {
            $exclude[] = new Group((string) $group->textContent);
        }

        return new Groups(
            GroupCollection::fromArray($include),
            GroupCollection::fromArray($exclude)
        );
    }

    private function getElementConfigurationParameters(\DOMElement $element): Extension
    {
        /** @psalm-var class-string $class */
        $class     = (string) $element->getAttribute('class');
        $file      = '';
        $arguments = $this->getConfigurationArguments($element->childNodes);

        if ($element->getAttribute('file')) {
            $file = $this->toAbsolutePath(
                (string) $element->getAttribute('file'),
                true
            );
        }

        return new Extension($class, $file, $arguments);
    }

    private function getColors(): string
    {
        $colors = DefaultResultPrinter::COLOR_DEFAULT;

        if ($this->document->documentElement->hasAttribute('colors')) {
            /* only allow boolean for compatibility with previous versions
              'always' only allowed from command line */
            if ($this->getBoolean($this->document->documentElement->getAttribute('colors'), false)) {
                $colors = DefaultResultPrinter::COLOR_AUTO;
            } else {
                $colors = DefaultResultPrinter::COLOR_NEVER;
            }
        }

        return $colors;
    }

    /**
     * @return int|string
     */
    private function getColumns()
    {
        $columns = 80;

        if ($this->document->documentElement->hasAttribute('columns')) {
            $columns = (string) $this->document->documentElement->getAttribute('columns');

            if ($columns !== 'max') {
                $columns = $this->getInteger($columns, 80);
            }
        }

        return $columns;
    }
}