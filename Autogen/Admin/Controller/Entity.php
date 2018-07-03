<?php

namespace DevHelper\Autogen\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\Entity\Entity as MvcEntity;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;

/**
 * @version 2018070301
 * @see \DevHelper\Autogen\Admin\Controller\Entity
 */
abstract class Entity extends AbstractController
{
    /**
     * @return \XF\Mvc\Reply\View
     */
    public function actionIndex()
    {
        $page = $this->filterPage();
        $perPage = $this->getPerPage();

        list($finder, $filters) = $this->entityListData();

        $finder->limitByPage($page, $perPage);
        $total = $finder->total();

        $viewParams = [
            'entities' => $finder->fetch(),

            'filters' => $filters,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total
        ];

        return $this->getViewReply('list', $viewParams);
    }

    /**
     * @return \XF\Mvc\Reply\View
     */
    public function actionAdd()
    {
        if (!$this->supportsAdding()) {
            return $this->noPermission();
        }

        return $this->entityAddEdit($this->createEntity());
    }

    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\View|\XF\Mvc\Reply\Redirect
     * @throws \XF\Mvc\Reply\Exception
     * @throws \XF\PrintableException
     */
    public function actionDelete(ParameterBag $params)
    {
        if (!$this->supportsDeleting()) {
            return $this->noPermission();
        }

        $entityId = $this->getEntityIdFromParams($params);
        $entity = $this->assertEntityExists($entityId);

        if ($this->isPost()) {
            $entity->delete();

            return $this->redirect($this->buildLink($this->getRoutePrefix()));
        }

        $viewParams = [
            'entity' => $entity,
            'entityLabel' => $this->getEntityLabel($entity)
        ];

        return $this->getViewReply('delete', $viewParams);
    }

    /**
     * @param ParameterBag $params
     * @return \XF\Mvc\Reply\View
     * @throws \XF\Mvc\Reply\Exception
     */
    public function actionEdit(ParameterBag $params)
    {
        if (!$this->supportsEditing()) {
            return $this->noPermission();
        }

        $entityId = $this->getEntityIdFromParams($params);
        $entity = $this->assertEntityExists($entityId);
        return $this->entityAddEdit($entity);
    }

    /**
     * @return \XF\Mvc\Reply\Error|\XF\Mvc\Reply\Redirect
     * @throws \Exception
     * @throws \XF\Mvc\Reply\Exception
     * @throws \XF\PrintableException
     */
    public function actionSave()
    {
        $this->assertPostOnly();

        $entityId = $this->filter('entity_id', 'str');
        if (!empty($entityId)) {
            $entity = $this->assertEntityExists($entityId);
        } else {
            $entity = $this->createEntity();
        }

        $this->entitySaveProcess($entity)->run();

        return $this->redirect($this->buildLink($this->getRoutePrefix()));
    }

    /**
     * @param MvcEntity $entity
     * @param string $columnName
     * @return string|null
     */
    public function getEntityColumnLabel($entity, $columnName)
    {
        $callback = [$entity, 'getEntityColumnLabel'];
        if (!is_callable($callback)) {
            $shortName = $entity->structure()->shortName;
            throw new \InvalidArgumentException("Entity {$shortName} does not implement {$callback[1]}");
        }

        return call_user_func($callback, $columnName);
    }

    /**
     * @param MvcEntity $entity
     * @return string
     */
    public function getEntityExplain($entity)
    {
        return '';
    }

    /**
     * @param MvcEntity $entity
     * @return string
     */
    public function getEntityHint($entity)
    {
        $structure = $entity->structure();
        if (!empty($structure->columns['display_order'])) {
            return sprintf('%s: %d', \XF::phrase('display_order'), $entity->get('display_order'));
        }

        return '';
    }

    /**
     * @param MvcEntity $entity
     * @return string|null
     */
    public function getEntityLabel($entity)
    {
        $callback = [$entity, 'getEntityLabel'];
        if (!is_callable($callback)) {
            $shortName = $entity->structure()->shortName;
            throw new \InvalidArgumentException("Entity {$shortName} does not implement {$callback[1]}");
        }

        return call_user_func($callback);
    }

    /**
     * @param int $entityId
     * @return MvcEntity
     * @throws \XF\Mvc\Reply\Exception
     */
    protected function assertEntityExists($entityId)
    {
        return $this->assertRecordExists($this->getShortName(), $entityId);
    }

    /**
     * @return MvcEntity
     */
    protected function createEntity()
    {
        return $this->em()->create($this->getShortName());
    }

    /**
     * @param MvcEntity $entity
     * @return \XF\Mvc\Reply\View
     */
    protected function entityAddEdit($entity)
    {
        $viewParams = [
            'entity' => $entity,
            'columns' => [],
        ];

        $structure = $entity->structure();
        $viewParams['columns'] = $this->entityGetMetadataForColumns($entity);

        foreach ($structure->relations as $relationKey => $relation) {
            if (empty($relation['entity']) ||
                empty($relation['type']) ||
                $relation['type'] !== MvcEntity::TO_ONE ||
                empty($relation['primary']) ||
                empty($relation['conditions'])) {
                continue;
            }

            $columnName = null;
            $relationConditions = $relation['conditions'];
            if (is_string($relationConditions)) {
                $columnName = $relationConditions;
            } elseif (is_array($relationConditions)) {
                if (count($relationConditions) === 1) {
                    $relationCondition = reset($relationConditions);
                    if (count($relationCondition) === 3 &&
                        $relationCondition[1] === '=' &&
                        preg_match('/\$(.+)$/', $relationCondition[2], $matches)) {
                        $columnName = $matches[1];
                    }
                }
            }
            if (empty($columnName) || !isset($viewParams['columns'][$columnName])) {
                continue;
            }
            $columnViewParamRef = &$viewParams['columns'][$columnName];
            list ($relationTag, $relationTagOptions) = $this->entityAddEditRelationColumn(
                $entity,
                $columnViewParamRef['_structureData'],
                $relationKey,
                $relation
            );

            if ($relationTag !== null) {
                $columnViewParamRef['tag'] = $relationTag;
                $columnViewParamRef['tagOptions'] = $relationTagOptions;
            }
        }

        return $this->getViewReply('edit', $viewParams);
    }

    /**
     * @param MvcEntity $entity
     * @param array $column
     * @param string $relationKey
     * @param array $relation
     * @return array
     */
    protected function entityAddEditRelationColumn($entity, array $column, $relationKey, array $relation)
    {
        $tag = null;
        $tagOptions = [];
        switch ($relation['entity']) {
            case 'XF:Forum':
                $tag = 'select';
                /** @var \XF\Repository\Node $nodeRepo */
                $nodeRepo = $entity->repository('XF:Node');
                $tagOptions['choices'] = $nodeRepo->getNodeOptionsData(false, ['Forum']);
                break;
            case 'XF:User':
                $tag = 'username';
                /** @var \XF\Entity\User $user */
                $user = $entity->getRelation($relationKey);
                $tagOptions['username'] = $user ? $user->username : '';
                break;
            default:
                if (strpos($relation['entity'], $this->getPrefixForClasses()) === 0) {
                    $choices = [];

                    /** @var MvcEntity $entity */
                    foreach ($this->finder($relation['entity'])->fetch() as $entity) {
                        $choices[] = [
                            'value' => $entity->getEntityId(),
                            'label' => $this->getEntityLabel($entity)
                        ];
                    }

                    $tag = 'select';
                    $tagOptions['choices'] = $choices;
                }
        }

        if ($tag === 'select') {
            if (isset($tagOptions['choices']) && empty($column['required'])) {
                array_unshift($tagOptions['choices'], [
                    'value' => 0,
                    'label' => '',
                ]);
            }
        }

        return [$tag, $tagOptions];
    }

    /**
     * @param \XF\Mvc\Entity\Entity $entity
     * @param string $columnName
     * @param array $column
     * @return array
     */
    protected function entityGetMetadataForColumn($entity, $columnName, array $column)
    {
        $columnTag = null;
        $columnTagOptions = [];
        $columnFilter = null;
        $requiresLabel = true;

        if (!$entity->exists()) {
            if (!empty($column['default'])) {
                $entity->set($columnName, $column['default']);
            }

            if ($this->request->exists($columnName)) {
                $input = $this->filter(['filters' => [$columnName => 'str']]);
                if (!empty($input['filters'][$columnName])) {
                    $entity->set($columnName, $this->filter($columnName, $input['filters'][$columnName]));
                    $requiresLabel = false;
                }
            }
        } else {
            if (!empty($column['writeOnce'])) {
                // do not render row for write once column, new value won't be accepted anyway
                return null;
            }
        }

        $columnLabel = $this->getEntityColumnLabel($entity, $columnName);
        if ($requiresLabel && empty($columnLabel)) {
            return null;
        }

        switch ($column['type']) {
            case MvcEntity::BOOL:
                $columnTag = 'radio';
                $columnTagOptions = [
                    'choices' => [
                        ['value' => 1, 'label' => \XF::phrase('yes')],
                        ['value' => 0, 'label' => \XF::phrase('no')],
                    ]
                ];
                $columnFilter = 'bool';
                break;
            case MvcEntity::INT:
                $columnTag = 'number-box';
                $columnFilter = 'int';
                break;
            case MvcEntity::UINT:
                $columnTag = 'number-box';
                $columnTagOptions['min'] = 0;
                $columnFilter = 'uint';
                break;
            case MvcEntity::STR:
                if (!empty($column['allowedValues'])) {
                    $choices = [];
                    foreach ($column['allowedValues'] as $allowedValue) {
                        $label = $allowedValue;
                        if (is_object($columnLabel) && $columnLabel instanceof \XF\Phrase) {
                            $labelPhraseName = $columnLabel->getName() . '_' .
                                preg_replace('/[^a-z]+/i', '_', $allowedValue);
                            $label = \XF::phraseDeferred($labelPhraseName);
                        }

                        $choices[] = [
                            'value' => $allowedValue,
                            'label' => $label
                        ];
                    }

                    $columnTag = 'select';
                    $columnTagOptions = ['choices' => $choices];
                } elseif (!empty($column['maxLength']) && $column['maxLength'] <= 255) {
                    $columnTag = 'text-box';
                } else {
                    $columnTag = 'text-area';
                }
                $columnFilter = 'str';
                break;
        }

        if ($columnTag === null || $columnFilter === null) {
            if (!empty($column['inputFilter']) && !empty($column['macroTemplate'])) {
                $columnTag = 'custom';
                $columnFilter = $column['inputFilter'];
            }
        }

        if ($columnTag === null || $columnFilter === null) {
            return null;
        }

        return [
            'filter' => $columnFilter,
            'label' => $columnLabel,
            'tag' => $columnTag,
            'tagOptions' => $columnTagOptions,
        ];
    }

    /**
     * @param \XF\Mvc\Entity\Entity $entity
     * @return array
     */
    protected function entityGetMetadataForColumns($entity)
    {
        $columns = [];
        $structure = $entity->structure();

        $getterColumns = [];
        foreach ($structure->getters as $getterKey => $getterCacheable) {
            if (isset($structureColumns[$getterKey]) || !$getterCacheable) {
                continue;
            }

            $columnLabel = $this->getEntityColumnLabel($entity, $getterKey);
            if (empty($columnLabel)) {
                continue;
            }

            $value = $entity->get($getterKey);
            if (!($value instanceof \XF\Phrase)) {
                continue;
            }

            $getterColumns[$getterKey] = [
                'isGetter' => true,
                'isNotValue' => true,
                'isPhrase' => true,
                'type' => MvcEntity::STR
            ];
        }

        $structureColumns = array_merge($getterColumns, $structure->columns);
        foreach ($structureColumns as $columnName => $column) {
            $metadata = $this->entityGetMetadataForColumn($entity, $columnName, $column);
            if (!is_array($metadata)) {
                continue;
            }

            $columns[$columnName] = $metadata;
            $columns[$columnName] += [
                '_structureData' => $column,
                'name' => sprintf('values[%s]', $columnName),
                'value' => $entity->get($columnName),
            ];
        }

        return $columns;
    }

    /**
     * @return array
     */
    protected function entityListData()
    {
        $shortName = $this->getShortName();
        $finder = $this->finder($shortName);

        $structure = $this->em()->getEntityStructure($shortName);
        if (!empty($structure->columns['display_order'])) {
            $finder->order('display_order');
        }

        $filters = ['pageNavParams' => []];

        return [$finder, $filters];
    }

    /**
     * @param \XF\Mvc\Entity\Entity $entity
     * @return FormAction
     */
    protected function entitySaveProcess($entity)
    {
        $filters = [];
        $columns = $this->entityGetMetadataForColumns($entity);
        foreach ($columns as $columnName => $metadata) {
            if (!empty($metadata['_structureData']['isNotValue'])) {
                continue;
            }

            $filters[$columnName] = $metadata['filter'];
        }

        $form = $this->formAction();
        $input = $this->filter(['values' => $filters]);
        $form->basicEntitySave($entity, $input['values']);

        $form->setup(function (FormAction $form) use ($columns, $entity) {
            $input = $this->filter([
                'hidden_columns' => 'array-str',
                'hidden_values' => 'array-str',
                'values' => 'array',
            ]);

            foreach ($input['hidden_columns'] as $columnName) {
                if (empty($input['hidden_values'][$columnName])) {
                    continue;
                }
                $entity->set($columnName, $input['hidden_values'][$columnName]);
            }

            foreach ($columns as $columnName => $metadata) {
                if (!isset($input['values'][$columnName])) {
                    continue;
                }

                if (!empty($metadata['_structureData']['isPhrase'])) {
                    /** @var \XF\Entity\Phrase $masterPhrase */
                    /** @noinspection PhpUndefinedMethodInspection */
                    $masterPhrase = $entity->getMasterPhrase($columnName);
                    $masterPhrase->phrase_text = $input['values'][$columnName];
                    $entity->addCascadedSave($masterPhrase);
                }
            }
        });

        $form->setup(function (FormAction $form) use ($entity) {
            $input = $this->filter([
                'username_columns' => 'array-str',
                'username_values' => 'array-str',
            ]);

            foreach ($input['username_columns'] as $columnName) {
                $userId = 0;
                if (!empty($input['username_values'][$columnName])) {
                    /** @var \XF\Repository\User $userRepo */
                    $userRepo = $this->repository('XF:User');
                    $user = $userRepo->getUserByNameOrEmail($input['username_values'][$columnName]);
                    if (empty($user)) {
                        $form->logError(\XF::phrase('requested_user_not_found'));
                    } else {
                        $userId = $user->user_id;
                    }
                }

                $entity->set($columnName, $userId);
            }
        });

        return $form;
    }

    /**
     * @param ParameterBag $params
     * @return int
     */
    protected function getEntityIdFromParams(ParameterBag $params)
    {
        $structure = $this->em()->getEntityStructure($this->getShortName());
        if (is_string($structure->primaryKey)) {
            return $params->get($structure->primaryKey);
        }

        return 0;
    }

    /**
     * @return int
     */
    protected function getPerPage()
    {
        return 20;
    }

    /**
     * @return array
     */
    protected function getViewLinks()
    {
        $routePrefix = $this->getRoutePrefix();
        $links = [
            'index' => $routePrefix,
            'save' => sprintf('%s/save', $routePrefix)
        ];

        if ($this->supportsAdding()) {
            $links['add'] = sprintf('%s/add', $routePrefix);
        }

        if ($this->supportsDeleting()) {
            $links['delete'] = sprintf('%s/delete', $routePrefix);
        }

        if ($this->supportsEditing()) {
            $links['edit'] = sprintf('%s/edit', $routePrefix);
        }

        if ($this->supportsViewing()) {
            $links['view'] = sprintf('%s/view', $routePrefix);
        }

        return $links;
    }

    /**
     * @return array
     */
    protected function getViewPhrases()
    {
        $prefix = $this->getPrefixForPhrases();

        $phrases = [];
        foreach ([
                     'add',
                     'edit',
                     'entities',
                     'entity',
                 ] as $partial) {
            $phrases[$partial] = \XF::phrase(sprintf('%s_%s', $prefix, $partial));
        }

        return $phrases;
    }

    /**
     * @param string $action
     * @param array $viewParams
     * @return \XF\Mvc\Reply\View
     */
    protected function getViewReply($action, array $viewParams)
    {
        $viewClass = sprintf('%s\Entity%s', $this->getShortName(), ucwords($action));
        $templateTitle = sprintf('%s_entity_%s', $this->getPrefixForTemplates(), strtolower($action));

        $viewParams['controller'] = $this;
        $viewParams['links'] = $this->getViewLinks();
        $viewParams['phrases'] = $this->getViewPhrases();

        return $this->view($viewClass, $templateTitle, $viewParams);
    }

    /**
     * @return bool
     */
    protected function supportsAdding()
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function supportsDeleting()
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function supportsEditing()
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function supportsViewing()
    {
        return false;
    }

    /**
     * @return string
     */
    abstract protected function getShortName();

    /**
     * @return string
     */
    abstract protected function getPrefixForClasses();

    /**
     * @return string
     */
    abstract protected function getPrefixForPhrases();

    /**
     * @return string
     */
    abstract protected function getPrefixForTemplates();

    /**
     * @return string
     */
    abstract protected function getRoutePrefix();

    // DevHelper/Autogen begins

    /**
     * @param \DevHelper\Util\AutogenContext $context
     * @throws \XF\PrintableException
     */
    public function devHelperAutogen($context)
    {
        $entity = $this->createEntity();

        $implementHints = $this->devHelperGetImplementHints($entity);
        if (count($implementHints) > 0) {
            $context->writeln(sprintf("%s should implement these:\n", $this->getShortName()));
            $context->writeln(implode("\n", $implementHints));
        }

        $structure = $entity->structure();
        \DevHelper\Util\Autogen\AdminRoute::autogen(
            $context,
            $this->getRoutePrefix(),
            $structure->primaryKey,
            str_replace('\Admin\Controller\\', ':', get_class($this))
        );

        $phraseTitlePartials = ['_entities', '_entity'];
        if ($this->supportsAdding()) {
            $phraseTitlePartials[] = '_add';
        }
        if ($this->supportsEditing()) {
            $phraseTitlePartials[] = '_edit';
        }
        foreach ($phraseTitlePartials as $phraseTitlePartial) {
            \DevHelper\Util\Autogen\Phrase::autogen($context, $this->getPrefixForPhrases() . $phraseTitlePartial);
        }

        $prefixForTemplates = $this->getPrefixForTemplates();
        $templateTitlePartials = ['list'];
        if ($this->supportsDeleting()) {
            $templateTitlePartials[] = 'delete';
        }
        if ($this->supportsEditing()) {
            $templateTitlePartials[] = 'edit';
        }
        foreach ($templateTitlePartials as $templateTitlePartial) {
            $templateTitleSource = "devhelper_autogen_ace_{$templateTitlePartial}";
            $templateTitleTarget = "{$prefixForTemplates}_entity_{$templateTitlePartial}";
            \DevHelper\Util\Autogen\AdminTemplate::autogen($context, $templateTitleSource, $templateTitleTarget);
        }

        $context->writeln(
            '<info>' . get_class($this) . ' OK</info>',
            \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERY_VERBOSE
        );
    }

    protected function devHelperGetImplementHints(\XF\Mvc\Entity\Entity $entity)
    {
        $implementHints = [];
        $docComments = [];
        $methods = [];

        $entityClass = get_class($entity);
        $rc = new \ReflectionClass($entityClass);
        while (true) {
            $parent = $rc->getParentClass();
            if ($parent->getName() === 'XF\Mvc\Entity\Entity' || $parent->isAbstract()) {
                break;
            }

            $rc = $parent;
            $entityClass = $rc->getName();
        }
        $rcDocComment = $rc->getDocComment();
        if (empty($rcDocComment)) {
            $entityClassAsArg = $entityClass;
            $entityClassAsArg = str_replace('\\Entity\\', ':', $entityClassAsArg);
            $entityClassAsArg = str_replace('\\', '\\\\', $entityClassAsArg);
            $docComments[] = "/**\n * Run `xf-dev--entity-class-properties.sh {$entityClassAsArg}`\n */";
        }

        $t = str_repeat(" ", 4);
        if ($this->supportsAdding() || $this->supportsEditing()) {
            try {
                $this->getEntityColumnLabel($entity, __METHOD__);
            } catch (\InvalidArgumentException $e) {
                $methods[] = "{$t}public function getEntityColumnLabel(\$columnName)\n{$t}{\n" .
                    "{$t}{$t}switch (\$columnName) {\n" .
                    "{$t}{$t}{$t}case 'column_1':\n" .
                    "{$t}{$t}{$t}case 'column_2':\n" .
                    "{$t}{$t}{$t}{$t}return \\XF::phrase('{$this->getPrefixForPhrases()}_' . \$columnName);\n" .
                    "{$t}{$t}}\n\n" .
                    "{$t}{$t}return null;\n" .
                    "{$t}}\n";
            } catch (\Exception $e) {
                // ignore
            }
        }
        try {
            $this->getEntityLabel($entity);
        } catch (\InvalidArgumentException $e) {
            $methods[] = "{$t}public function getEntityLabel()\n{$t}{\n{$t}{$t}return \$this->column_name;\n{$t}}\n";
        } catch (\Exception $e) {
            // ignore
        }

        if (count($docComments) === 0 && count($methods) === 0) {
            return $implementHints;
        }

        if (count($docComments) > 0) {
            foreach ($docComments as $docComment) {
                $implementHints[] = $docComment;
            }
        }

        $implementHints[] = "class X extends Entity\n{\n";

        if (count($methods) > 0) {
            $implementHints[] = "{$t}...\n";
            foreach ($methods as $method) {
                $implementHints[] = $method;
            }
            $implementHints[] = "{$t}...\n";
        }

        $implementHints[] = '}';

        return $implementHints;
    }

    // DevHelper/Autogen ends
}