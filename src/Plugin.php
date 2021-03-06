<?php
namespace Hostnet\Component\AccessorGenerator;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Hostnet\Component\AccessorGenerator\Annotation\Generate;
use Hostnet\Component\AccessorGenerator\Generator\CodeGenerator;
use Hostnet\Component\AccessorGenerator\Generator\CodeGeneratorInterface;
use Hostnet\Component\AccessorGenerator\Reflection\ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Plugin that will generate accessor methods in traits and puts them
 * in a Generated folder and namespace relative for the file the trait
 * is created for.
 *
 * The generated trait should be included in the class manually after
 * generation.
 *
 * The plugin will only generate traits for classes in the application and
 * classes in composer-packages that directly require this package.
 *
 * For more information on usage please see README.md
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    const NAME = 'hostnet/accessor-generator-plugin-lib';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var CodeGenerator
     */
    private $generator;

    /**
     * Initialize the annotation registry with composer as auto loader. Create
     * a CodeGenerator if none was provided.
     *
     * @param CodeGeneratorInterface $generator
     * @throws \InvalidArgumentException
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Syntax
     */
    public function __construct(CodeGeneratorInterface $generator = null)
    {
        AnnotationRegistry::registerLoader('class_exists');

        if ($generator) {
            $this->generator = $generator;
        } else {
            $this->generator = new CodeGenerator();
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => ['onPreAutoloadDump', 20],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;
    }

    /**
     * Gets called on the PRE_AUTOLOAD_DUMP event
     *
     * Generate Traits for every package that requires
     * this plugin and has php files with the @Generate
     * annotation set on at least one property.
     *
     * @throws \DomainException
     * @throws \Hostnet\Component\AccessorGenerator\Generator\Exception\TypeUnknownException
     * @throws \Hostnet\Component\AccessorGenerator\Reflection\Exception\ClassDefinitionNotFoundException
     * @throws \Hostnet\Component\AccessorGenerator\Reflection\Exception\FileException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     */
    public function onPreAutoloadDump()
    {
        $local_repository = $this->composer->getRepositoryManager()->getLocalRepository();
        $packages         = $local_repository->getPackages();
        $packages[]       = $this->composer->getPackage();

        $extra = $this->composer->getPackage()->getExtra();
        isset($extra['accessor-generator']) && $this->generator->setEncryptionAliases($extra['accessor-generator']);

        foreach ($packages as $package) {
            /* @var $package PackageInterface */
            if (array_key_exists(self::NAME, $package->getRequires())) {
                $this->generateTraitForPackage($package);
            }
        }
    }

    /**
     * Generate traits on disk for the given package.
     * Will only do so when the package actually has
     * the @Generate annotation set on at least one
     * property.
     *
     * @throws \DomainException
     * @throws \Hostnet\Component\AccessorGenerator\Generator\Exception\TypeUnknownException
     * @throws \Hostnet\Component\AccessorGenerator\Reflection\Exception\ClassDefinitionNotFoundException
     * @throws \Hostnet\Component\AccessorGenerator\Reflection\Exception\FileException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     * @throws \Symfony\Component\Filesystem\Exception\IOException
     *
     * @param PackageInterface $package
     */
    private function generateTraitForPackage(PackageInterface $package)
    {
        if ($this->io->isVerbose()) {
            $this->io->write('Generating metadata for <info>' . $package->getPrettyName() . '</info>');
        }

        foreach ($this->getFilesForPackage($package) as $filename) {
            $reflection_class = new ReflectionClass($filename);
            if ($this->generator->writeTraitForClass($reflection_class) && $this->io->isVeryVerbose()) {
                $this->io->write("  - generated metadata for <info>$filename</info>");
            }
        }

        // At the end of generating the Traits for each package we need to write the KeyRegistry classes
        // which hold the encryption key paths.
        $this->generator->writeKeyRegistriesForPackage();
    }

    /**
     * Find all the PHP files within a package.
     *
     * Excludes
     *  - all files in VCS directories
     *  - all files in vendor folders
     *  - all files in Generated folders
     *  - all hidden files
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     *
     * @param PackageInterface $package
     * @return \Iterator
     */
    private function getFilesForPackage(PackageInterface $package)
    {
        if ($package instanceof RootPackageInterface) {
            $path = '.';
        } else {
            $path = $this->composer->getInstallationManager()->getInstallPath($package);
        }

        $finder = new Finder();

        return $finder
            ->ignoreVCS(true)
            ->ignoreDotFiles(true)
            ->exclude(['vendor', 'Generated'])
            ->name('*.php')
            ->in($path)
            ->getIterator();
    }
}
