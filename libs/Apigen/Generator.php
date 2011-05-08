<?php

/**
 * ApiGen - API Generator.
 *
 * Copyright (c) 2010 David Grudl (http://davidgrudl.com)
 * Copyright (c) 2011 Ondřej Nešpor (http://andrewsville.cz)
 * Copyright (c) 2011 Jaroslav Hanslík (http://kukulich.cz)
 *
 * This source file is subject to the "Nette license", and/or
 * GPL license. For more information please see http://nette.org
 */

namespace Apigen;

use Nette;
use Apigen\Reflection as ApiReflection, Apigen\Exception, Apigen\Config, Apigen\Template, Apigen\Backend;
use TokenReflection\Broker;
use TokenReflection\IReflectionClass as ReflectionClass, TokenReflection\IReflectionProperty as ReflectionProperty, TokenReflection\IReflectionMethod as ReflectionMethod, TokenReflection\IReflectionConstant as ReflectionConstant;


/**
 * Generates a HTML API documentation.
 *
 * @author David Grudl
 * @author Ondřej Nešpor
 */
class Generator extends Nette\Object
{
	/**
	 * Library version.
	 *
	 * @var string
	 */
	const VERSION = '2.0 beta';

	/**
	 * Configuration.
	 *
	 * @var \Apigen\Config
	 */
	private $config;

	/**
	 * Progressbar
	 *
	 * @var \Console_ProgressBar
	 */
	private $progressBar;

	/**
	 * Array of reflection envelopes.
	 *
	 * @var array
	 */
	private $classes = array();

	/**
	 * Sets configuration.
	 *
	 * @param array $config
	 */
	public function __construct(Config $config)
	{
		$this->config = $config;
	}

	/**
	 * Scans and parses PHP files.
	 *
	 * @return array
	 */
	public function parse()
	{
		$broker = new Broker(new Backend(), false);

		$files = array();
		foreach ($this->config->source as $source) {
			$entries = array();
			if (is_dir($source)) {
				foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source)) as $entry) {
					if (!$entry->isFile()) {
						continue;
					}
					$entries[] = $entry;
				}
			} else {
				$entries[] = new \SplFileInfo($source);
			}

			foreach ($entries as $entry) {
				if (!preg_match('~\\.php$~i', $entry->getFilename())) {
					continue;
				}
				foreach ($this->config->exclude as $mask) {
					if (fnmatch($mask, $entry->getPathName(), FNM_NOESCAPE | FNM_PATHNAME)) {
						continue 2;
					}
				}

				$files[$entry->getPathName()] = $entry->getSize();
			}
		}

		if (empty($files)) {
			throw new Exception("No PHP files found.");
		}

		if ($this->config->progressbar) {
			$this->prepareProgressBar(array_sum($files));
		}

		foreach ($files as $file => $size) {
			$broker->processFile($file);
			$this->incrementProgressBar($size);
		}

		$generator = $this;
		$this->classes = array_map(function(ReflectionClass $class) use ($generator) {
			return new ApiReflection($class, $generator);
		}, $broker->getClasses(Backend::TOKENIZED_CLASSES | Backend::INTERNAL_CLASSES | Backend::NONEXISTENT_CLASSES));

		return array(
			count($broker->getClasses(Backend::TOKENIZED_CLASSES)),
			count($broker->getClasses(Backend::INTERNAL_CLASSES))
		);
	}

	/**
	 * Returns configuration.
	 *
	 * @return mixed
	 */
	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * Returns parsed class list.
	 *
	 * @return array
	 */
	public function getClasses()
	{
		return $this->classes;
	}

	/**
	 * Wipes out the destination directory.
	 *
	 * @return boolean
	 */
	public function wipeOutDestination()
	{
		// resources
		foreach ($this->config->resources as $dir) {
			$pathName = $this->config->destination . '/' . $dir;
			if (is_dir($pathName)) {
				$this->deleteDir($pathName);
			}
		}

		// common files
		$filenames = array_keys($this->config->templates['common']);
		foreach (Nette\Utils\Finder::findFiles($filenames)->from($this->config->destination) as $item) {
			if (!@unlink($item)) {
				return false;
			}
		}

		// optional files
		foreach ($this->config->templates['optional'] as $optional) {
			$file = $this->config->destination . '/' . $optional['filename'];
			if (is_file($file) && !@unlink($file)) {
				return false;
			}
		}

		// output files
		$masks = array_map(function($mask) {
			return preg_replace('~%[^%]*?s~', '*', $mask);
		}, $this->config->filenames);
		$filter = function($item) use($masks) {
			foreach ($masks as $mask) {
				if (fnmatch($mask, $item->getFilename())) {
					return true;
				}
			}

			return false;
		};

		foreach (Nette\Utils\Finder::findFiles('*')->filter($filter)->from($this->config->destination) as $item) {
			if (!@unlink($item)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generates API documentation.
	 */
	public function generate()
	{
		@mkdir($this->config->destination);
		if (!is_dir($this->config->destination)) {
			throw new Exception("Directory {$this->config->destination} doesn't exist.");
		}

		$destination = $this->config->destination;
		$templatePath = $this->config->templateDir . '/' . $this->config->template;

		// copy resources
		foreach ($this->config->resources as $source => $dest) {
			foreach ($iterator = Nette\Utils\Finder::findFiles('*')->from($templatePath . '/' . $source)->getIterator() as $foo) {
				copy($iterator->getPathName(), $this->forceDir("$destination/$dest/" . $iterator->getSubPathName()));
			}
		}

		// categorize by namespaces
		$packages = array();
		$namespaces = array();
		$allClasses = array();
		foreach ($this->classes as $class) {
			if ($class->isDocumented()) {
				$packages[$class->getPackageName()]['classes'][$class->getName()] = $class;
				if ($class->inNamespace()) {
					$packages[$class->getPackageName()]['namespaces'][$class->getNamespaceName()] = true;
					$namespaces[$class->getNamespaceName()]['classes'][$class->getShortName()] = $class;
					$namespaces[$class->getNamespaceName()]['packages'][$class->getPackageName()] = true;
				}
				$allClasses[$class->getName()] = $class;
			}
		}

		// add missing parent namespaces
		foreach (array_keys($namespaces) as $name) {
			$parent = '';
			foreach (explode('\\', $name) as $part) {
				$parent = ltrim($parent . '\\' . $part, '\\');
				if (!isset($namespaces[$parent])) {
					$namespaces[$parent] = array('classes' => array(), 'packages' => array());
				}
			}
		}

		uksort($packages, 'strcasecmp');
		uksort($namespaces, 'strcasecmp');
		uksort($allClasses, 'strcasecmp');

		$classes = array_filter($allClasses, function($class) {
			return !$class->isInterface() && !$class->isException();
		});
		$interfaces = array_filter($allClasses, function($class) {
			return $class->isInterface();
		});
		$exceptions = array_filter($allClasses, function($class) {
			return $class->isException();
		});

		if ($this->config->progressbar) {
			$max = count($allClasses)
				+ count($namespaces)
				+ count($packages)
				+ count($this->config->templates['common'])
				+ (int) $this->config->deprecated // list of deprecated elements
				+ (int) $this->config->todo // list of tasks
				+ (int) !empty($this->config->baseUrl) // sitemap
				+ (int) (!empty($this->config->googleCse) && !empty($this->config->baseUrl)) // opensearch
				+ (int) !empty($this->config->googleCse) // autocomplete
			;

			if ($this->config->code) {
				$max += count(array_filter($allClasses, function(ApiReflection $class) {
					return $class->isTokenized();
				}));
			}

			$this->prepareProgressBar($max);
		}

		// create tmp directory
		$tmp = $this->config->destination . DIRECTORY_SEPARATOR . 'tmp';
		@mkdir($tmp, 0755, true);

		// prepare template
		$template = new Template($this);
		$template->setCacheStorage(new Nette\Templating\PhpFileStorage($tmp));
		$template->version = self::VERSION;
		$template->config = $this->config;

		// generate summary files
		$template->namespace = null;
		$template->namespaces = array_keys($namespaces);
		$template->package = null;
		$template->packages = array_keys($packages);
		$template->class = null;
		$template->classes = $classes;
		$template->interfaces = $interfaces;
		$template->exceptions = $exceptions;
		foreach ($this->config->templates['common'] as $dest => $source) {
			$template->setFile($templatePath . '/' . $source)->save($this->forceDir("$destination/$dest"));

			$this->incrementProgressBar();
		}

		// optional files
		if (!empty($this->config->baseUrl)) {
			$template->setFile($templatePath . '/' . $this->config->templates['optional']['sitemap']['template'])->save($this->forceDir($destination . '/' . $this->config->templates['optional']['sitemap']['filename']));
			$this->incrementProgressBar();
		}
		if (!empty($this->config->baseUrl) && !empty($this->config->googleCse)) {
			$template->setFile($templatePath . '/' . $this->config->templates['optional']['opensearch']['template'])->save($this->forceDir($destination . '/' . $this->config->templates['optional']['opensearch']['filename']));
			$this->incrementProgressBar();
		}
		if (!empty($this->config->googleCse)) {
			$template->setFile($templatePath . '/' . $this->config->templates['optional']['autocomplete']['template'])->save($this->forceDir($destination . '/' . $this->config->templates['optional']['autocomplete']['filename']));
			$this->incrementProgressBar();
		}

		// list of deprecated elements
		if ($this->config->deprecated) {
			$template->deprecatedClasses = array_filter($classes, function($class) {
				return $class->isDeprecated();
			});
			$template->deprecatedInterfaces = array_filter($interfaces, function($class) {
				return $class->isDeprecated();
			});
			$template->deprecatedExceptions = array_filter($exceptions, function($class) {
				return $class->isDeprecated();
			});

			$template->deprecatedMethods = array();
			$template->deprecatedConstants = array();
			$template->deprecatedProperties = array();
			foreach ($allClasses as $class) {
				if ($class->isDeprecated()) {
					continue;
				}

				$template->deprecatedMethods += array_filter($class->getOwnMethods(), function($method) {
					return $method->isDeprecated();
				});
				$template->deprecatedConstants += array_filter($class->getOwnConstantReflections(), function($constant) {
					return $constant->isDeprecated();
				});
				$template->deprecatedProperties += array_filter($class->getOwnProperties(), function($property) {
					return $property->isDeprecated();
				});
			}

			$template->setFile($templatePath . '/' . $this->config->templates['optional']['deprecated']['template'])->save($this->forceDir($destination . '/' . $this->config->templates['optional']['deprecated']['filename']));

			$this->incrementProgressBar();
		}

		// list of tasks
		if ($this->config->todo) {
			$template->todoClasses = array_filter($classes, function($class) {
				return $class->hasAnnotation('todo');
			});
			$template->todoInterfaces = array_filter($interfaces, function($class) {
				return $class->hasAnnotation('todo');
			});
			$template->todoExceptions = array_filter($exceptions, function($class) {
				return $class->hasAnnotation('todo');
			});

			$template->todoMethods = array();
			$template->todoConstants = array();
			$template->todoProperties = array();
			foreach ($allClasses as $class) {
				$template->todoMethods += array_filter($class->getOwnMethods(), function($method) {
					return $method->hasAnnotation('todo');
				});
				$template->todoConstants += array_filter($class->getOwnConstantReflections(), function($constant) {
					return $constant->hasAnnotation('todo');
				});
				$template->todoProperties += array_filter($class->getOwnProperties(), function($property) {
					return $property->hasAnnotation('todo');
				});
			}

			$template->setFile($templatePath . '/' . $this->config->templates['optional']['todo']['template'])->save($this->forceDir($destination . '/' . $this->config->templates['optional']['todo']['filename']));

			$this->incrementProgressBar();
		}

		// generate namespace summary
		$this->forceDir($destination . '/' . $this->config->filenames['namespace']);
		$template->package = null;
		foreach ($namespaces as $namespace => $definition) {
			$classes = isset($definition['classes']) ? $definition['classes'] : array();
			uksort($classes, 'strcasecmp');
			$nPackages = isset($definition['packages']) ? array_keys($definition['packages']) : array();
			usort($nPackages, 'strcasecmp');
			$template->package = 1 === count($nPackages) ? $nPackages[0] : null;
			$template->packages = $nPackages;
			$template->namespace = $namespace;
			$template->namespaces = array_filter(array_keys($namespaces), function($item) use($namespace) {
				return strpos($item, $namespace) === 0 || strpos($namespace, $item) === 0;
			});
			$template->classes = array_filter($classes, function($class) {
				return !$class->isInterface() && !$class->isException();
			});
			$template->interfaces = array_filter($classes, function($class) {
				return $class->isInterface() && !$class->isException();
			});
			$template->exceptions = array_filter($classes, function($class) {
				return $class->isException();
			});
			$template->setFile($templatePath . '/' . $this->config->templates['namespace'])->save($destination . '/' . $this->getNamespaceLink($namespace));

			$this->incrementProgressBar();
		}

		// generate package summary
		$this->forceDir($destination . '/' . $this->config->filenames['package']);
		$template->namespace = null;
		foreach ($packages as $package => $definition) {
			$classes = isset($definition['classes']) ? $definition['classes'] : array();
			uksort($classes, 'strcasecmp');
			$pNamespaces = isset($definition['namespaces']) ? array_keys($definition['namespaces']) : array();
			usort($pNamespaces, 'strcasecmp');
			$template->package = $package;
			$template->packages = array($package);
			$template->namespaces = $pNamespaces;
			$template->classes = array_filter($classes, function($class) {
				return !$class->isInterface() && !$class->isException();
			});
			$template->interfaces = array_filter($classes, function($class) {
				return $class->isInterface() && !$class->isException();
			});
			$template->exceptions = array_filter($classes, function($class) {
				return $class->isException();
			});
			$template->setFile($templatePath . '/' . $this->config->templates['package'])->save($destination . '/' . $this->getPackageLink($package));

			$this->incrementProgressBar();
		}


		// generate class & interface files
		$fshl = new \fshlParser('HTML_UTF8', P_TAB_INDENT | P_LINE_COUNTER);
		$this->forceDir($destination . '/' . $this->config->filenames['class']);
		$this->forceDir($destination . '/' . $this->config->filenames['source']);
		$template->classes = $allClasses;
		foreach ($allClasses as $class) {
			$template->package = $package = $class->getPackageName();
			$template->namespace = $namespace = $class->getNamespaceName();
			if ($namespace) {
				$template->namespaces = array_filter(array_keys($namespaces), function($item) use($namespace) {
					return strpos($item, $namespace) === 0 || strpos($namespace, $item) === 0;
				});
			} else {
				$template->namespaces = array();
			}
			$template->packages = array($package);
			$template->tree = array_merge(array_reverse($class->getParentClasses()), array($class));
			$template->classes = !$class->isInterface() && !$class->isException() ? array($class) : array();
			$template->interfaces = $class->isInterface() && !$class->isException() ? array($class) : array();
			$template->exceptions = $class->isException() ? array($class) : array();

			$template->directSubClasses = $class->getDirectSubClasses();
			uksort($template->directSubClasses, 'strcasecmp');
			$template->indirectSubClasses = $class->getIndirectSubClasses();
			uksort($template->indirectSubClasses, 'strcasecmp');

			$template->directImplementers = $class->getDirectImplementers();
			uksort($template->directImplementers, 'strcasecmp');
			$template->indirectImplementers = $class->getIndirectImplementers();
			uksort($template->indirectImplementers, 'strcasecmp');

			$template->ownMethods = $class->getOwnMethods();
			$template->ownConstants = $class->getOwnConstantReflections();
			$template->ownProperties = $class->getOwnProperties();

			if ($class->isTokenized()) {
				$template->fileName = null;
				$file = $class->getFileName();
				foreach ($this->config->source as $source) {
					if (0 === strpos($file, $source)) {
						$template->fileName = str_replace('\\', '/', substr($file, strlen($source) + 1));
						break;
					}
				}
				if (null === $template->fileName) {
					throw new Exception(sprintf('Could not determine class %s relative path', $class->getName()));
				}
			}

			$template->class = $class;
			$template->setFile($templatePath . '/' . $this->config->templates['class'])->save($destination . '/' . $this->getClassLink($class));

			$this->incrementProgressBar();

			// generate source codes
			if ($this->config->code && $class->isTokenized()) {
				$source = file_get_contents($class->getFileName());
				$source = str_replace(array("\r\n", "\r"), "\n", $source);

				$template->source = $fshl->highlightString('PHP', $source);
				$template->setFile($templatePath . '/' . $this->config->templates['source'])->save($destination . '/' . $this->getSourceLink($class, false));

				$this->incrementProgressBar();
			}
		}

		// delete tmp directory
		$this->deleteDir($tmp);
	}

	/**
	 * Prints message if printing is enabled.
	 */
	public function output($message)
	{
		if (!$this->config->quiet) {
			echo $message;
		}
	}

	/**
	 * Returns header.
	 *
	 * @return string
	 */
	public static function getHeader()
	{
		$name = sprintf('ApiGen %s', self::VERSION);
		return $name . "\n" . str_repeat('-', strlen($name)) . "\n";
	}

	/**
	 * Returns a link to a namespace summary file.
	 *
	 * @param  string|\Apigen\Reflection|IReflectionNamespace
	 * @return string
	 */
	public function getNamespaceLink($class)
	{
		$namespace = ($class instanceof ApiReflection) ? $class->getNamespaceName() : $class;
		return sprintf($this->config->filenames['namespace'], $namespace ? preg_replace('#[^a-z0-9_]#i', '.', $namespace) : 'None');
	}

	/**
	 * Returns a link to a package summary file.
	 *
	 * @param string|\Apigen\Reflection
	 * @return string
	 */
	public function getPackageLink($class)
	{
		$package = ($class instanceof ApiReflection) ? $class->getPackageName() : $class;
		return sprintf($this->config->filenames['package'], $package ? preg_replace('#[^a-z0-9_]#i', '.', $package) : 'None');
	}

	/**
	 * Returns a link to class summary file.
	 *
	 * @param string|\Apigen\Reflection $class
	 * @return string
	 */
	public function getClassLink($class)
	{
		if ($class instanceof ApiReflection) {
			$class = $class->getName();
		}

		return sprintf($this->config->filenames['class'], preg_replace('#[^a-z0-9_]#i', '.', $class));
	}

	/**
	 * Returns a link to method in class summary file.
	 *
	 * @param IReflectionMethod $method
	 * @return string
	 */
	public function getMethodLink(ReflectionMethod $method)
	{
		return $this->getClassLink($method->getDeclaringClassName()) . '#_' . $method->getName();
	}

	/**
	 * Returns a link to property in class summary file.
	 *
	 * @param IReflectionProperty $property
	 * @return string
	 */
	public function getPropertyLink(ReflectionProperty $property)
	{
		return $this->getClassLink($property->getDeclaringClassName()) . '#$' . $property->getName();
	}

	/**
	 * Returns a link to constant in class summary file.
	 *
	 * @param IReflectionConstant $constant
	 * @return string
	 */
	public function getConstantLink(ReflectionConstant $constant)
	{
		return $this->getClassLink($constant->getDeclaringClassName()) . '#' . $constant->getName();
	}

	/**
	 * Returns a link to a element source code.
	 *
	 * @param \Apigen\Reflection|IReflectionMethod|IReflectionProperty|IReflectionConstant $element
	 * @return string
	 */
	public function getSourceLink($element, $withLine = true)
	{
		$class = $element instanceof ApiReflection ? $element : $element->getDeclaringClass();

		$file = str_replace('\\', '/', $class->getName());

		$line = null;
		if ($withLine) {
			$line = $element->getStartLine();
			if ($doc = $element->getDocComment()) {
				$line -= substr_count($doc, "\n") + 1;
			}
		}

		return sprintf($this->config->filenames['source'], preg_replace('#[^a-z0-9_]#i', '.', $file)) . (isset($line) ? "#$line" : '');
	}

	/**
	 * Returns a link to a element documentation at php.net.
	 *
	 * @param \Apigen\Reflection|IReflectionMethod|IReflectionProperty|IReflectionConstant $element
	 * @return string
	 */
	public function getManualLink($element)
	{
		static $manual = 'http://php.net/manual';
		static $reservedClasses = array('stdClass', 'Closure', 'Directory');

		$class = $element instanceof ApiReflection ? $element : $element->getDeclaringClass();

		if (in_array($class->getName(), $reservedClasses)) {
			return $manual . '/reserved.classes.php';
		}

		$className = strtolower($class->getName());
		$classLink = sprintf('%s/class.%s.php', $manual, $className);
		$elementName = strtolower(strtr(ltrim($element->getName(), '_'), '_', '-'));

		if ($element instanceof ApiReflection) {
			return $classLink;
		} elseif ($element instanceof ReflectionMethod) {
			return sprintf('%s/%s.%s.php', $manual, $className, $elementName);
		} elseif ($element instanceof ReflectionProperty) {
			return sprintf('%s#%s.props.%s', $classLink, $className, $elementName);
		} elseif ($element instanceof ReflectionConstant) {
			return sprintf('%s#%s.constants.%s', $classLink, $className, $elementName);
		}
	}

	/**
	 * Prepares the progressbar.
	 *
	 * @param $maximum Maximum progressbar value
	 */
	private function prepareProgressBar($maximum = 1)
	{
		$this->progressBar = new \Console_ProgressBar(
			'[%bar%] %percent%',
			'=>',
			' ',
			80,
			$maximum
		);
	}

	/**
	 * Increments the progressbar by one.
	 *
	 * @param integer $increment Progressbar increment
	 */
	private function incrementProgressBar($increment = 1)
	{
		if ($this->config->progressbar) {
			$this->progressBar->update($this->progressBar->getProgress() + $increment);
		}
	}

	/**
	 * Ensures a directory is created.
	 *
	 * @param string Directory path
	 * @return string
	 */
	private function forceDir($path)
	{
		@mkdir(dirname($path), 0755, true);
		return $path;
	}

	/**
	 * Deletes a directory.
	 *
	 * @param string $path Directory path
	 * @return boolean
	 */
	private function deleteDir($path)
	{
		foreach (Nette\Utils\Finder::find('*')->from($path)->childFirst() as $item) {
			if ($item->isDir()) {
				if (!@rmdir($item)) {
					return false;
				}
			} elseif ($item->isFile()) {
				if (!@unlink($item)) {
					return false;
				}
			}
		}
		if (@rmdir($path)) {
			return false;
		}

		return true;
	}
}
