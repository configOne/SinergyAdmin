<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\DependencyInjection\Compiler;

use Doctrine\Common\Inflector\Inflector;
use Sonata\AdminBundle\Admin\AbstractAdmin;
use Sonata\AdminBundle\Controller\CRUDController;
use Sonata\AdminBundle\Datagrid\Pager;
use Sonata\AdminBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Add all dependencies to the Admin class, this avoids writing too many lines
 * in the configuration files.
 *
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 * @author Timur Murtukov <murtukov@gmail.com>
 */
class AddDependencyCallsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        // check if translator service exists
        if (!$container->has('translator')) {
            throw new \RuntimeException('The "translator" service is not yet enabled.
                It\'s required by SonataAdmin to display all labels properly.

                To learn how to enable the translator service please visit:
                http://symfony.com/doc/current/translation.html#configuration
             ');
        }

        $parameterBag  = $container->getParameterBag();
        $groupDefaults = $admins = $classes = [];

        $pool = $container->getDefinition('sonata.admin.pool');

        $configConst = $container->getParameter('sonata.admin.configuration.config_const');

        foreach ($container->findTaggedServiceIds('sonata.admin') as $serviceId => $tags) {
            $attributes = $tags[0];
            $definition = $container->getDefinition($serviceId);

            $config = $this->mergeInlineConfigs($definition->getClass(), $configConst);

            // This parameters were applied through constructor, now manually
            $definition->addMethodCall('setCode', [$definition->getClass()]);
            $definition->addMethodCall('setClass', [$config['entity']]);
            $definition->addMethodCall('setBaseControllerName', [$config['controller']]);
            $definition->addMethodCall('setManagerType', [$config['controller']]);

            $attributes['label'] = $config['label'];

            // Add scalar config values as tag attributes to share them between
            // other compiler passes (DoctrineORMAdminBundle etc.)
            $allTags = $definition->getTags();
            foreach ($config as $key => $value) {
                if(is_scalar($value)) {
                    $allTags['sonata.admin'][0][$key] = $value;
                }
            }
            $definition->setTags($allTags);


            // Temporary fix until we can support service locators
            $definition->setPublic(true);

            $this->applyDefaults($container, $serviceId, $attributes, $config);

            $admins[] = $serviceId;

            if (!isset($classes[$config['controller']])) {
                $classes[$config['controller']] = [];
            }

            $classes[$config['controller']][] = $serviceId;

            $showInDashboard = (bool) (isset($attributes['show_in_dashboard']) ? $parameterBag->resolveValue($attributes['show_in_dashboard']) : true);
            if (!$showInDashboard) {
                continue;
            }

            $resolvedGroupName = isset($config['group']) ? $parameterBag->resolveValue($config['group']) : 'default';

            $labelCatalogue = $attributes['label_catalogue'] ?? 'SonataAdminBundle';
            $icon           = $attributes['icon']            ?? '<i class="fa fa-folder"></i>';
            $onTop          = $attributes['on_top']          ?? false;
            $keepOpen       = $attributes['keep_open']       ?? false;

            if (!isset($groupDefaults[$resolvedGroupName])) {
                $groupDefaults[$resolvedGroupName] = [
                    'label' => $resolvedGroupName,
                    'label_catalogue' => $labelCatalogue,
                    'icon' => $icon,
                    'roles' => [],
                    'on_top' => false,
                    'keep_open' => false,
                ];
            }

            $groupDefaults[$resolvedGroupName]['items'][] = [
                'admin' => $serviceId,
                'label' => !empty($attributes['label']) ? $attributes['label'] : '',
                'route' => '',
                'route_params' => [],
                'route_absolute' => false,
            ];

            if (isset($groupDefaults[$resolvedGroupName]['on_top']) && $groupDefaults[$resolvedGroupName]['on_top']
                || $onTop && (count($groupDefaults[$resolvedGroupName]['items']) > 1)) {
                throw new \RuntimeException('You can\'t use "on_top" option with multiple same name groups.');
            }
            $groupDefaults[$resolvedGroupName]['on_top'] = $onTop;

            $groupDefaults[$resolvedGroupName]['keep_open'] = $keepOpen;
        }

        $dashboardGroupsSettings = $container->getParameter('sonata.admin.configuration.dashboard_groups');
        if (!empty($dashboardGroupsSettings)) {
            $groups = $dashboardGroupsSettings;

            foreach ($dashboardGroupsSettings as $groupName => $group) {
                $resolvedGroupName = $parameterBag->resolveValue($groupName);
                if (!isset($groupDefaults[$resolvedGroupName])) {
                    $groupDefaults[$resolvedGroupName] = [
                        'items' => [],
                        'label' => $resolvedGroupName,
                        'roles' => [],
                        'on_top' => false,
                        'keep_open' => false,
                    ];
                }

                if (empty($group['items'])) {
                    $groups[$resolvedGroupName]['items'] = $groupDefaults[$resolvedGroupName]['items'];
                }

                if (empty($group['label'])) {
                    $groups[$resolvedGroupName]['label'] = $groupDefaults[$resolvedGroupName]['label'];
                }

                if (empty($group['label_catalogue'])) {
                    $groups[$resolvedGroupName]['label_catalogue'] = 'SonataAdminBundle';
                }

                if (empty($group['icon'])) {
                    $groups[$resolvedGroupName]['icon'] = $groupDefaults[$resolvedGroupName]['icon'];
                }

                if (!empty($group['item_adds'])) {
                    $groups[$resolvedGroupName]['items'] = array_merge($groups[$resolvedGroupName]['items'], $group['item_adds']);
                }

                if (empty($group['roles'])) {
                    $groups[$resolvedGroupName]['roles'] = $groupDefaults[$resolvedGroupName]['roles'];
                }

                if (isset($groups[$resolvedGroupName]['on_top']) && !empty($group['on_top']) && $group['on_top']
                    && (count($groups[$resolvedGroupName]['items']) > 1)) {
                    throw new \RuntimeException('You can\'t use "on_top" option with multiple same name groups.');
                }
                if (empty($group['on_top'])) {
                    $groups[$resolvedGroupName]['on_top'] = $groupDefaults[$resolvedGroupName]['on_top'];
                }

                if (empty($group['keep_open'])) {
                    $groups[$resolvedGroupName]['keep_open'] = $groupDefaults[$resolvedGroupName]['keep_open'];
                }
            }
        } elseif ($container->getParameter('sonata.admin.configuration.sort_admins')) {
            $groups = $groupDefaults;

            $elementSort = function (&$element) {
                usort(
                    $element['items'],
                    function ($a, $b) {
                        $a = !empty($a['label']) ? $a['label'] : $a['admin'];
                        $b = !empty($b['label']) ? $b['label'] : $b['admin'];

                        if ($a === $b) {
                            return 0;
                        }

                        return $a < $b ? -1 : 1;
                    }
                );
            };

            /*
             * 1) sort the groups by their index
             * 2) sort the elements within each group by label/admin
             */
            ksort($groups);
            array_walk($groups, $elementSort);
        } else {
            $groups = $groupDefaults;
        }

        $pool->addMethodCall('setAdminServiceIds', [$admins]);
        $pool->addMethodCall('setAdminGroups', [$groups]);
        $pool->addMethodCall('setAdminClasses', [$classes]);

        $routeLoader = $container->getDefinition('sonata.admin.route_loader');
        $routeLoader->replaceArgument(1, $admins);
    }

    /**
     * This method reads the attribute keys and configures admin class to use the related dependency.
     *
     * TODO: remove/modify this method as tag attributes will not be provided anymore
     */
//    public function applyConfigurationFromAttribute(Definition $definition, array $attributes)
//    {
//        $keys = [
//            'model_manager',
//            'form_contractor',
//            'show_builder',
//            'list_builder',
//            'datagrid_builder',
//            'translator',
//            'configuration_pool',
//            'router',
//            'validator',
//            'security_handler',
//            'menu_factory',
//            'route_builder',
//            'label_translator_strategy',
//        ];
//
//        foreach ($keys as $key) {
//            $method = 'set'.Inflector::classify($key);
//            if (!isset($attributes[$key]) || $definition->hasMethodCall($method)) {
//                continue;
//            }
//
//            $definition->addMethodCall($method, [new Reference($attributes[$key])]);
//        }
//    }

    /**
     * Apply the default values required by the AdminInterface to the Admin service definition.
     *
     * @param string $serviceId
     *
     * @return Definition
     */
    public function applyDefaults($container, $serviceId, $attributes = [], $config)
    {
        $definition = $container->getDefinition($serviceId);
        $definition->setShared(false);


        // This comes from yaml
        $yamlConfig = $container->getParameter('sonata.admin.configuration.admin_services');

        // Get yaml config of certain admin service
        $yamlAdminConfig = $yamlConfig[$serviceId] ?? [];

        $yamlPersistFilters = $container->getParameter('sonata.admin.configuration.filters.persist');

        $managerType = $config['manager_type'];

        $defaultAddServices = [
            'model_manager'     => "sonata.admin.manager.$managerType",
            'form_contractor'   => "sonata.admin.builder.{$managerType}_form",
            'show_builder'      => "sonata.admin.builder.{$managerType}_show",
            'list_builder'      => "sonata.admin.builder.{$managerType}_list",
            'datagrid_builder'  => "sonata.admin.builder.{$managerType}_datagrid",
            'translator'        => $config['translator'],
            'configuration_pool'=> $config['configuration_pool'],
            'route_generator'   => $config['route_generator'],
            'validator'         => $config['validator'],
            'security_handler'  => $config['security_handler'],
            'menu_factory'      => $config['menu_factory'],
            'label_translator_strategy' => $config['label_translator_strategy'],
            'route_builder'     => 'sonata.admin.route.path_info'.
                (('doctrine_phpcr' == $managerType) ? '_slashes' : ''),
        ];

        $definition->addMethodCall('setManagerType', [$managerType]);

        foreach ($defaultAddServices as $attr => $addServiceId) {
            $method = 'set'.Inflector::classify($attr); // 'route_builder' => 'routBuilder'

            if (isset($yamlAdminConfig[$attr]) || !$definition->hasMethodCall($method)) {
                $args = [new Reference($yamlAdminConfig[$attr] ?? $addServiceId)];

                if ('translator' === $attr) {
                    $args[] = false;
                }

                $definition->addMethodCall($method, $args);
            }
        }

        $definition->addMethodCall('setPagerType', [$yamlAdminConfig['pager_type'] ?? $config['pager_type']]);
        $definition->addMethodCall('setLabel', [$yamlAdminConfig['label'] ?? $config['label']]);
        $definition->addMethodCall('setPersistFilters', [$config['persist_filters'] ?? $yamlPersistFilters]);
        $definition->addMethodCall('showMosaicButton', [$yamlAdminConfig['show_mosaic_button'] ?? $config['show_mosaic_button']]);
        $definition->addMethodCall('setExportFormats', [$config['export_formats']]);

        if(isset($config['base_route_name'])) {
            $definition->addMethodCall('setBaseRouteName', [$config['base_route_name']]);
        }

        if(isset($config['base_route_pattern'])) {
            $definition->addMethodCall('setBaseRoutePattern', [$config['base_route_pattern']]);
        }

        $this->fixTemplates($container, $definition, $yamlAdminConfig['templates'] ?? ['view' => []]);

        if ($container->hasParameter('sonata.admin.configuration.security.information') && !$definition->hasMethodCall('setSecurityInformation')) {
            $definition->addMethodCall('setSecurityInformation', ['%sonata.admin.configuration.security.information%']);
        }

        $definition->addMethodCall('initialize');

        return $definition;
    }

    /**
     * TODO: understand what this method does
     *
     * @param ContainerBuilder $container
     * @param Definition $definition
     * @param array $overwrittenTemplates
     */
    public function fixTemplates(ContainerBuilder $container, Definition $definition, array $overwrittenTemplates = [])
    {
        $definedTemplates = $container->getParameter('sonata.admin.configuration.templates');

        $methods = [];
        $pos = 0;
        foreach ($definition->getMethodCalls() as $method) {
            if ('setTemplates' == $method[0]) {
                $definedTemplates = array_merge($definedTemplates, $method[1][0]);

                continue;
            }

            if ('setTemplate' == $method[0]) {
                $definedTemplates[$method[1][0]] = $method[1][1];

                continue;
            }

            // set template for simple pager if it is not already overwritten
            if ('setPagerType' === $method[0]
                && $method[1][0] === Pager::TYPE_SIMPLE
                && (
                    !isset($definedTemplates['pager_results'])
                    || '@SonataAdmin/Pager/results.html.twig' === $definedTemplates['pager_results']
                )
            ) {
                $definedTemplates['pager_results'] = '@SonataAdmin/Pager/simple_pager_results.html.twig';
            }

            $methods[$pos] = $method;
            ++$pos;
        }

        $definition->setMethodCalls($methods);

        $definedTemplates = $overwrittenTemplates['view'] + $definedTemplates;

        if ($container->getParameter('sonata.admin.configuration.templates') !== $definedTemplates) {
            $definition->addMethodCall('setTemplates', [$definedTemplates]);
        } else {
            $definition->addMethodCall('setTemplates', ['%sonata.admin.configuration.templates%']);
        }
    }

    /**
     * Merge all inline configuration up to AbstractAdmin class
     *
     * TODO:
     *  - dont use $configConst variable when reached AbstractAdmin class
     *
     * @author Timur Murtukov <murtukov@gmail.com>
     */
    private function mergeInlineConfigs(string $class, $configConst)
    {
        $reflector = new \ReflectionClass($class);
        $config = $reflector->getConstant($configConst);

        if(!$config) {
            $config = [];
        }

        // Walk through all parent classes till AbstractAdmin, merging found configuration
        while(true) {
            // Reached AbstractAdmin
            if($reflector->getName() == AbstractAdmin::class) break;

            // Wrong inheritance
            if(!$parent = $reflector->getParentClass()) {
                throw new \RuntimeException(
                    "Class {$reflector->getName()} should extend '" . AbstractAdmin::class . "' class"
                );
            }

            $reflector = $parent;
            $parentConfig = $reflector->getConstant($configConst);

            // No config found, pass this class
            if(!$parentConfig) continue;

            $config = array_merge($parentConfig, $config);
        }

        return $config;
    }
}
