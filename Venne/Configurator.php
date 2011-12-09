<?php

/**
 * Venne:CMS (version 2.0-dev released on $WCDATE$)
 *
 * Copyright (c) 2011 Josef Kříž pepakriz@gmail.com
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Venne;

use Nette,
	Nette\Caching\Cache,
	Nette\DI,
	Nette\Diagnostics\Debugger,
	Venne\Modules,
	Nette\Application\Routers\SimpleRouter,
	Nette\Application\Routers\Route,
	\Nette\Config\NeonAdapter;

/**
 * @author     Josef Kříž
 * 
 * @property-read \Nette\Application\Container $container
 */
class Configurator extends \Nette\Configurator {



	public function __construct($params, $containerClass = 'Venne\DI\Container')
	{
		parent::__construct($containerClass);

		$this->container->addService("services", new \Venne\DI\Container($this->container));
		$this->container->addService("modules", new \Venne\Module\Container($this->container));
		$this->container->addService("themes", new \Venne\DI\Container($this->container));

		/*
		 * Params
		 */
		$this->container->params += (array) $params;
		$this->container->params['flashes'] = array(
			'success' => "success",
			'error' => "error",
			'info' => "info",
			'warning' => "warning",
		);

		$this->container->params["venneModeInstallation"] = false;
		$this->container->params["venneModeAdmin"] = false;
		$this->container->params["venneModeFront"] = false;
		$this->container->params["venneModulesNamespace"] = "\\Venne\\Modules\\";

		/*
		 * Detect mode
		 */
		$url = explode("/", substr($this->container->httpRequest->url->path, strlen($this->container->httpRequest->url->basePath)), 2);
		if ($url[0] == "admin") {
			$this->container->params["venneModeAdmin"] = true;
		} else if ($url[0] == "installation") {
			$this->container->params["venneModeInstallation"] = true;
		} else {
			$this->container->params["venneModeFront"] = true;
		}


		/*
		 * Set mode
		 */
		$config = NeonAdapter::load($this->container->params["appDir"] . "/global.neon");
		$this->container->params['mode'] = $config["mode"];
	}



	/**
	 * Loads configuration from file and process it.
	 * @return DI\Container
	 */
	public function loadConfig($file, $section = NULL)
	{
		$container = parent::loadConfig($file, $section);

		/*
		 * Set Debugger
		 */
		$debugger = $this->container->params["debugger"];
		if ($debugger["mode"] == "production") {
			$this->container->params['productionMode'] = true;
		} else if ($debugger["mode"] == "development") {
			$this->container->params['productionMode'] = false;
		} else {
			$this->container->params['productionMode'] = $this->detectProductionMode();
		}
		Debugger::$strictMode = true;
		Debugger::enable(
				$debugger['developerIp'] && $this->container->params['productionMode'] ? (array) $debugger['developerIp'] : $this->container->params['productionMode'], $debugger['logDir'], $debugger['logEmail']
		);


		$this->container->params["moduleNamespaces"] = array("\\App\\", "\\");
		$this->container->params['modules'] = $this->container->params['modules'];

		foreach ($this->container->modules->getModules() as $key => $module) {
			$class = ucfirst($key) . "Module\\Module";
			foreach ($this->container->params["moduleNamespaces"] as $ns) {
				if (class_exists($ns . $class)) {
					$class = $ns . $class;
					break;
				}
			}
			$this->container->modules->addService($key, new $class);
		}

		$this->container->addService("cmsManager", new \App\CoreModule\CmsManager($this->container));

		foreach ($this->container->modules->getModules() as $key => $module) {
			$this->container->modules->$key->configure($this->container, $this->container->cmsManager);
		}

		$this->setRoutes($container->application->router);


		// load themes
		foreach ($this->container->scannerService->getThemes() as $skin) {
			$class = "\\" . ucfirst($skin) . "Theme\\Theme";
			$this->container->themes->addService($skin, new $class($container));
		}

		return $container;
	}



	/**
	 * @param \Nette\Application\Routers\RouteList
	 */
	public function setRoutes(\Nette\Application\Routers\RouteList $router)
	{
		$prefix = $this->container->params["website"]["routePrefix"];
		if ($this->container->params["website"]["multilang"]) {
			$langs = array();
			foreach ($this->container->languageRepository->findAll() as $entity) {
				$langs[] = $entity->alias;
			}
			$prefix = str_replace("<lang>/", "[<lang " . implode("|", $langs) . ">/]", $prefix);
		}



		/**
		 * Administration
		 */
		$router[] = $adminRouter = new \Venne\Application\Routers\RouteList("admin");
		$adminRouter[] = new Route('admin/<module>/<presenter>[/<action>[/<id>]]', array(
					'module' => "Core",
					'presenter' => 'Default',
					'action' => 'default',
				));

		/*
		 * Installation
		 */
		if (!file_exists($this->container->params["flagsDir"] . "/installed")) {
			$router[] = new Route('[<url .+>]', "Installation:Admin:Default:", Route::ONE_WAY);
		}


		/*
		 * Upgrade
		 */
		if ($this->container->modules->checkModuleUpgrades()) {
			$router[] = new Route('[<url .+>]', "Modules:Admin:Default:", Route::ONE_WAY);
		}

		$router[] = new Application\Routers\DbRoute($this->container->cmsManager, $this->container->pageRepository, $this->container->languageRepository, $this->container->cacheStorage, $prefix, $this->container->params["website"]["multilang"], $this->container->params["website"]["defaultLangAlias"]);


		/*
		 * Default route
		 */
		if ($prefix) {
			$router[] = new Route($prefix . '<presenter>/<action>', array(
						"presenter" => $this->container->params["website"]["defaultPresenter"],
						"action" => "default",
						"lang" => NULL
					));
		}
		$router[] = new Route('', array(
					"presenter" => $this->container->params["website"]["defaultPresenter"],
					"lang" => NULL
						), Route::ONE_WAY);
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Venne\Doctrine\Container
	 */
	public static function createServiceDoctrineContainer(\Nette\DI\IContainer $container)
	{
		return new Doctrine\Container($container);
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Venne\Doctrine\Container
	 */
	public static function createServiceModuleManager(\Nette\DI\IContainer $container)
	{
		return new ModuleManager\Manager($container, $container->doctrineContainer->entityManager);
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Nette\Database\Connection
	 */
	public static function createServiceDatabaseService(\Nette\DI\IContainer $container)
	{
		$driver = substr($container->params['database']['driver'], 4);
		$host = $container->params['database']['host'];
		$dbname = $container->params['database']['dbname'];
		$user = $container->params['database']['user'];
		$password = $container->params['database']['password'];

		return new Nette\Database\Connection("$driver:host=$host;dbname=$dbname", $user, $password);
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Venne\Security\Authenticator
	 */
	public static function createServiceAuthenticator(\Nette\DI\IContainer $container)
	{
		return new \App\SecurityModule\Authenticator($container);
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \App\SecurityModule\Authorizator
	 */
	public static function createServiceAuthorizator(\Nette\DI\IContainer $container)
	{
		$authorizator = new \App\SecurityModule\AuthorizatorFactory($container);
		return $authorizator->create();
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Venne\RoutingManager
	 */
	public static function createServiceRouting(\Nette\DI\IContainer $container)
	{
		return new RoutingManager\Service($container);
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Venne\Latte\DefaultMacros
	 */
	public static function createServiceMacros(\Nette\DI\IContainer $container)
	{
		return new \Venne\Latte\DefaultMacros;
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Nella\Localization\ITranslator
	 */
	public static function createServiceTranslator(\Nette\DI\IContainer $container)
	{
		$translator = new \Venne\Localization\Translator();
		$translator->setLang("cs");

		$file = $container->params["wwwDir"] . "/themes/" . $container->params["website"]["theme"];
		$translator->addDictionary('Venne', $file);

		foreach ($container->params['modules'] as $key => $module) {
			$file = $container->params["appDir"] . "/" . ucfirst($key) . "Module";
			if (file_exists($file)) {
				$translator->addDictionary($key, $file);
			}
		}

		if ($container->params["venneModeAdmin"]) {
			$file = $container->params["wwwDir"] . "/themes/admin";
			$translator->addDictionary('Administration', $file);
		}

		return $translator;
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Nella\Localization\Panel
	 */
	public static function createServiceTranslatorPanel(\Nette\DI\IContainer $container)
	{
		return new \Venne\Localization\Panel($container);
	}



	/**
	 * @param \Nette\DI\IContainer
	 * @return \Nette\Latte\Engine
	 */
	public static function createServiceLatteEngine(\Nette\DI\IContainer $container)
	{
		$engine = new Nette\Latte\Engine();
		return $engine;
	}



	/**
	 * @param \Nette\DI\IContainer $container
	 * @return Templating\TemplateContainer 
	 */
	public static function createServiceTemplateContainer(\Nette\DI\IContainer $container)
	{
		return new Templating\TemplateContainer($container->latteEngine, $container->translator);
	}



	/**
	 * @return Nette\Loaders\RobotLoader
	 */
	public static function createServiceRobotLoader(DI\Container $container, array $options = NULL)
	{
		$loader = new Nette\Loaders\RobotLoader;
		$loader->autoRebuild = isset($options['autoRebuild']) ? $options['autoRebuild'] : !$container->params['productionMode'];
		$loader->setCacheStorage($container->cacheStorage);
		if (isset($options['directory'])) {
			$loader->addDirectory($options['directory']);
		} else {
			foreach (array('appDir', 'libsDir', 'themesDir') as $var) {
				if (isset($container->params[$var])) {
					$loader->addDirectory($container->params[$var]);
				}
			}
		}
		$loader->register();
		return $loader;
	}



	/**
	 * @return \Venne\Application\IPresenterFactory
	 */
	public static function createServicePresenterFactory(DI\Container $container)
	{
		return new \Venne\Application\PresenterFactory($container);
	}



	/**
	 * @return \Doctrine\ORM\EntityManager
	 */
	public static function createServiceEntityManager(DI\Container $container)
	{
		return $container->doctrineContainer->entityManager;
	}

}
