<?php

/**
 * Venne:CMS (version 2.0-dev released on $WCDATE$)
 *
 * Copyright (c) 2011 Josef Kříž pepakriz@gmail.com
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Venne\Module;

/**
 * @author Josef Kříž
 */
abstract class BaseModule implements IModule {



	/**
	 *
	 * @return array
	 */
	public function getDependencies()
	{
		return array();
	}



	public function configure(\Venne\DI\Container $container, \App\CoreModule\CmsManager $manager)
	{
		
	}



	public function setPermissions(\Venne\DI\Container $container, \Nette\Security\Permission $permissions)
	{
		
	}



	public function install(\Venne\DI\Container $container)
	{
		
	}



	public function uninstall(\Venne\DI\Container $container)
	{
		
	}



	public function setMigrations(\Venne\Doctrine\Migration\BaseMigration $class)
	{
		
	}



	public function getForm(\Venne\Config\ConfigBuilder $configManager)
	{
		return new \App\CoreModule\ModuleForm($configManager, $this->getName());
	}

}

