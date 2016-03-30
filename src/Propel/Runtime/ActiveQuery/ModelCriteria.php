<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT License
 */

namespace Propel\Runtime\ActiveQuery;

use Propel\Generator\Model\NamingTool;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Event\DeleteEvent;
use Propel\Runtime\Event\SaveEvent;
use Propel\Runtime\Events;
use Propel\Runtime\Exception\RuntimeException;
use Propel\Runtime\Propel;
use Propel\Runtime\Collection\ObjectCollection;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Exception\ClassNotFoundException;
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Exception\UnexpectedValueException;
use Propel\Runtime\Map\FieldMap;
use Propel\Runtime\Map\RelationMap;
use Propel\Runtime\Map\EntityMap;
use Propel\Runtime\Util\PropelModelPager;
use Propel\Runtime\ActiveQuery\Criterion\AbstractCriterion;
use Propel\Runtime\ActiveQuery\Criterion\InModelCriterion;
use Propel\Runtime\ActiveQuery\Criterion\BasicModelCriterion;
use Propel\Runtime\ActiveQuery\Criterion\CustomCriterion;
use Propel\Runtime\ActiveQuery\Criterion\LikeModelCriterion;
use Propel\Runtime\ActiveQuery\Criterion\RawCriterion;
use Propel\Runtime\ActiveQuery\Criterion\RawModelCriterion;
use Propel\Runtime\ActiveQuery\Criterion\SeveralModelCriterion;
use Propel\Runtime\ActiveQuery\Exception\UnknownFieldException;
use Propel\Runtime\ActiveQuery\Exception\UnknownModelException;
use Propel\Runtime\ActiveQuery\Exception\UnknownRelationException;
use Propel\Runtime\DataFetcher\DataFetcherInterface;

/**
 * This class extends the Criteria by adding runtime introspection abilities
 * in order to ease the building of queries.
 *
 * A ModelCriteria requires additional information to be initialized.
 * Using a model name and entitymaps, a ModelCriteria can do more powerful things than a simple Criteria
 *
 * magic methods:
 *
 * @method ModelCriteria leftJoin($relation) Adds a LEFT JOIN clause to the query
 * @method ModelCriteria rightJoin($relation) Adds a RIGHT JOIN clause to the query
 * @method ModelCriteria innerJoin($relation) Adds a INNER JOIN clause to the query
 *
 * @author François Zaninotto
 */
class ModelCriteria extends BaseModelCriteria
{
    const FORMAT_STATEMENT  = '\Propel\Runtime\Formatter\StatementFormatter';
    const FORMAT_ARRAY      = '\Propel\Runtime\Formatter\ArrayFormatter';
    const FORMAT_OBJECT     = '\Propel\Runtime\Formatter\ObjectFormatter';
    const FORMAT_ON_DEMAND  = '\Propel\Runtime\Formatter\OnDemandFormatter';

    protected $primaryCriteria;

    protected $isWithOneToMany = false;

    // this is introduced to prevent useQuery->join from going wrong
    protected $previousJoin = null;

    // whether to clone the current object before termination methods
    protected $isKeepQuery = true;

    // this is for the select method
    protected $select = null;

    // temporary property used in replaceNames
    protected $currentAlias;

    /**
     * @param string   $entityAlias
     * @param Criteria $criteria
     *
     * @return static|$this
     */
    public static function create($entityAlias = null, Criteria $criteria = null)
    {
        $query = new static;
        if (null !== $entityAlias) {
            $query->setEntityAlias($entityAlias);
        }

        if ($criteria instanceof Criteria) {
            $query->mergeWith($criteria);
        }

        return $query;
    }
    /**
     * Adds a condition on a field based on a pseudo SQL clause
     * but keeps it for later use with combine()
     * Until combine() is called, the condition is not added to the query
     * Uses introspection to translate the field phpName into a fully qualified name
     * <code>
     * $c->condition('cond1', 'b.Title = ?', 'foo');
     * </code>
     *
     * @see Criteria::add()
     *
     * @param string $conditionName A name to store the condition for a later combination with combine()
     * @param string $clause        The pseudo SQL clause, e.g. 'AuthorId = ?'
     * @param mixed  $value         A value for the condition
     * @param mixed  $bindingType   A value for the condition
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function condition($conditionName, $clause, $value = null, $bindingType = null)
    {
        $this->addCond($conditionName, $this->getCriterionForClause($clause, $value, $bindingType), null, $bindingType);

        return $this;
    }

    /**
     * Adds a condition on a field based on a field phpName and a value
     * Uses introspection to translate the field phpName into a fully qualified name
     * Warning: recognizes only the phpNames of the main Model (not joined entities)
     * <code>
     * $c->filterBy('Title', 'foo');
     * </code>
     *
     * @see Criteria::add()
     *
     * @param string $field     A string representing thefield phpName, e.g. 'AuthorId'
     * @param mixed  $value      A value for the condition
     * @param string $comparison What to use for the field comparison, defaults to Criteria::EQUAL
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function filterBy($field, $value, $comparison = Criteria::EQUAL)
    {
        return $this->add($this->getRealFieldName($field), $value, $comparison);
    }

    /**
     * Adds a list of conditions on the fields of the current model
     * Uses introspection to translate the field phpName into a fully qualified name
     * Warning: recognizes only the phpNames of the main Model (not joined entities)
     * <code>
     * $c->filterByArray(array(
     *  'Title'     => 'War And Peace',
     *  'Publisher' => $publisher
     * ));
     * </code>
     *
     * @see filterBy()
     *
     * @param mixed $conditions An array of conditions, using field phpNames as key
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function filterByArray($conditions)
    {
        foreach ($conditions as $field => $args) {
            call_user_func_array(array($this, 'filterBy' . $field), is_array($args) ? $args : array($args));
        }

        return $this;
    }

    /**
     * Adds a condition on a field based on a pseudo SQL clause
     * Uses introspection to translate the field phpName into a fully qualified name
     * <code>
     * // simple clause
     * $c->where('b.Title = ?', 'foo');
     * // named conditions
     * $c->condition('cond1', 'b.Title = ?', 'foo');
     * $c->condition('cond2', 'b.ISBN = ?', 12345);
     * $c->where(array('cond1', 'cond2'), Criteria::LOGICAL_OR);
     * </code>
     *
     * @see Criteria::add()
     *
     * @param mixed $clause A string representing the pseudo SQL clause, e.g. 'Book.AuthorId = ?'
     *                      Or an array of condition names
     * @param mixed $value  A value for the condition
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function where($clause, $value = null, $bindingType = null)
    {
        if (is_array($clause)) {
            // where(array('cond1', 'cond2'), Criteria::LOGICAL_OR)
            $criterion = $this->getCriterionForConditions($clause, $value);
        } else {
            // where('Book.AuthorId = ?', 12)
            $criterion = $this->getCriterionForClause($clause, $value, $bindingType);
        }

        $this->addUsingOperator($criterion, null, null);

        return $this;
    }

    /**
     * Adds a having condition on a field based on a pseudo SQL clause
     * Uses introspection to translate the field phpName into a fully qualified name
     * <code>
     * // simple clause
     * $c->having('b.Title = ?', 'foo');
     * // named conditions
     * $c->condition('cond1', 'b.Title = ?', 'foo');
     * $c->condition('cond2', 'b.ISBN = ?', 12345);
     * $c->having(array('cond1', 'cond2'), Criteria::LOGICAL_OR);
     * </code>
     *
     * @see Criteria::addHaving()
     *
     * @param mixed $clause A string representing the pseudo SQL clause, e.g. 'Book.AuthorId = ?'
     *                      Or an array of condition names
     * @param mixed $value  A value for the condition
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function having($clause, $value = null, $bindingType = null)
    {
        if (is_array($clause)) {
            // having(array('cond1', 'cond2'), Criteria::LOGICAL_OR)
            $criterion = $this->getCriterionForConditions($clause, $value);
        } else {
            // having('Book.AuthorId = ?', 12)
            $criterion = $this->getCriterionForClause($clause, $value, $bindingType);
        }

        $this->addHaving($criterion);

        return $this;
    }

    /**
     * Adds an ORDER BY clause to the query
     * Usability layer on top of Criteria::addAscendingOrderByField() and Criteria::addDescendingOrderByField()
     * Infers $field and $order from $fieldName and some optional arguments
     * Examples:
     *   $c->orderBy('Book.CreatedAt')
     *    => $c->addAscendingOrderByField(BookEntityMap::CREATED_AT)
     *   $c->orderBy('Book.CategoryId', 'desc')
     *    => $c->addDescendingOrderByField(BookEntityMap::CATEGORY_ID)
     *
     * @param string $fieldName The field to order by
     * @param string $order      The sorting order. Criteria::ASC by default, also accepts Criteria::DESC
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function orderBy($fieldName, $order = Criteria::ASC)
    {
        list(, $realFieldName) = $this->getFieldFromName($fieldName, false);
        $order = strtoupper($order);
        switch ($order) {
            case Criteria::ASC:
                $this->addAscendingOrderByField($realFieldName);
                break;
            case Criteria::DESC:
                $this->addDescendingOrderByField($realFieldName);
                break;
            default:
                throw new UnexpectedValueException('ModelCriteria::orderBy() only accepts Criteria::ASC or Criteria::DESC as argument');
        }

        return $this;
    }

    /**
     * Adds a GROUP BY clause to the query
     * Usability layer on top of Criteria::addGroupByField()
     * Infers $field $fieldName
     * Examples:
     *   $c->groupBy('Book.AuthorId')
     *    => $c->addGroupByField(BookEntityMap::AUTHOR_ID)
     *
     * @param string $fieldName The field to group by
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function groupBy($fieldName)
    {
        list(, $realFieldName) = $this->getFieldFromName($fieldName, false);
        $this->addGroupByField($realFieldName);

        return $this;
    }

    /**
     * Adds a GROUP BY clause for all fields of a model to the query
     * Examples:
     *   $c->groupBy('Book');
     *    => $c->addGroupByField(BookEntityMap::ID);
     *    => $c->addGroupByField(BookEntityMap::TITLE);
     *    => $c->addGroupByField(BookEntityMap::AUTHOR_ID);
     *    => $c->addGroupByField(BookEntityMap::PUBLISHER_ID);
     *
     * @param string $class The class name or alias
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function groupByClass($class)
    {
        if ($class == $this->getModelAliasOrName()) {
            // field of the Criteria's model
            $entityMap = $this->getEntityMap();
        } elseif (isset($this->joins[$class])) {
            // field of a relations's model
            $entityMap = $this->joins[$class]->getEntityMap();
        } else {
            throw new ClassNotFoundException(sprintf('Unknown model or alias: %s.', $class));
        }

        foreach ($entityMap->getFields() as $field) {
            if (isset($this->aliases[$class])) {
                $this->addGroupByField($class . '.' . $field->getName());
            } else {
                $this->addGroupByField($field->getFullyQualifiedName());
            }
        }

        return $this;
    }

    /**
     * Adds a DISTINCT clause to the query
     * Alias for Criteria::setDistinct()
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function distinct()
    {
        $this->setDistinct();

        return $this;
    }

    /**
     * Adds a LIMIT clause (or its subselect equivalent) to the query
     * Alias for Criteria:::setLimit()
     *
     * @param int $limit Maximum number of results to return by the query
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function limit($limit)
    {
        $this->setLimit($limit);

        return $this;
    }

    /**
     * Adds an OFFSET clause (or its subselect equivalent) to the query
     * Alias for of Criteria::setOffset()
     *
     * @param int $offset Offset of the first result to return
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function offset($offset)
    {
        $this->setOffset($offset);

        return $this;
    }

    /**
     * Makes the ModelCriteria return a string, array, or ArrayCollection
     * Examples:
     *   ArticleQuery::create()->select('Name')->find();
     *   => ArrayCollection Object ('Foo', 'Bar')
     *
     *   ArticleQuery::create()->select('Name')->findOne();
     *   => string 'Foo'
     *
     *   ArticleQuery::create()->select(array('Id', 'Name'))->find();
     *   => ArrayCollection Object (
     *        array('Id' => 1, 'Name' => 'Foo'),
     *        array('Id' => 2, 'Name' => 'Bar')
     *      )
     *
     *   ArticleQuery::create()->select(array('Id', 'Name'))->findOne();
     *   => array('Id' => 1, 'Name' => 'Foo')
     *
     * @param mixed $fieldArray A list of field names (e.g. array('Title', 'Category.Name', 'c.Content')) or a single field name (e.g. 'Name')
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function select($fieldArray)
    {
        if (!count($fieldArray) || empty($fieldArray)) {
            throw new PropelException('You must ask for at least one field');
        }

        if ('*' === $fieldArray) {
            $fieldArray = array();
            foreach (call_user_func(array($this->entityMap, 'getFieldNames'), EntityMap::TYPE_PHPNAME) as $field) {
                $fieldArray []= $this->entityName . '.' . $field;
            }
        }

        $this->select = $fieldArray;

        return $this;
    }

    /**
     * Retrieves the fields defined by a previous call to select().
     * @see select()
     *
     * @return array|string A list of field names (e.g. array('Title', 'Category.Name', 'c.Content')) or a single field name (e.g. 'Name')
     */
    public function getSelect()
    {
        return $this->select;
    }

    /**
     * This method returns the previousJoin for this ModelCriteria,
     * by default this is null, but after useQuery this is set the to the join of that use
     *
     * @return Join the previousJoin for this ModelCriteria
     */
    public function getPreviousJoin()
    {
        return $this->previousJoin;
    }

    /**
     * This method sets the previousJoin for this ModelCriteria,
     * by default this is null, but after useQuery this is set the to the join of that use
     *
     * @param Join $previousJoin The previousJoin for this ModelCriteria
     */
    public function setPreviousJoin(Join $previousJoin)
    {
        $this->previousJoin = $previousJoin;
    }

    /**
     * This method returns an already defined join clause from the query
     *
     * @param string $name The name of the join clause
     *
     * @return Join A join object
     */
    public function getJoin($name)
    {
        return $this->joins[$name];
    }

    /**
     * Adds a JOIN clause to the query
     * Infers the ON clause from a relation name
     * Uses the Propel entity maps, based on the schema, to guess the related fields
     * Beware that the default JOIN operator is INNER JOIN, while Criteria defaults to WHERE
     * Examples:
     * <code>
     *   $c->join('Book.Author');
     *    => $c->addJoin(BookEntityMap::AUTHOR_ID, AuthorEntityMap::ID, Criteria::INNER_JOIN);
     *   $c->join('Book.Author', Criteria::RIGHT_JOIN);
     *    => $c->addJoin(BookEntityMap::AUTHOR_ID, AuthorEntityMap::ID, Criteria::RIGHT_JOIN);
     *   $c->join('Book.Author a', Criteria::RIGHT_JOIN);
     *    => $c->addAlias('a', AuthorEntityMap::TABLE_NAME);
     *    => $c->addJoin(BookEntityMap::AUTHOR_ID, 'a.ID', Criteria::RIGHT_JOIN);
     * </code>
     *
     * @param string $relation Relation to use for the join
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function join($relation, $joinType = Criteria::INNER_JOIN)
    {
        // relation looks like '$leftName.$relationName $relationAlias'
        list($fullName, $relationAlias) = self::getClassAndAlias($relation);
        if (false === strpos($fullName, '.')) {
            // simple relateion name, refers to the current entity
            $leftName = $this->getModelAliasOrName();
            $relationName = $fullName;
            $previousJoin = $this->getPreviousJoin();
            $entityMap = $this->getEntityMap();
        } else {
            list($leftName, $relationName) = explode('.', $fullName);
            $shortLeftName = self::getShortName($leftName);
            // find the EntityMap for the left entity using the $leftName
            if ($leftName === $this->getModelAliasOrName() || $leftName === $this->getModelShortName()) {
                $previousJoin = $this->getPreviousJoin();
                $entityMap = $this->getEntityMap();
            } elseif (isset($this->joins[$leftName])) {
                $previousJoin = $this->joins[$leftName];
                $entityMap = $previousJoin->getEntityMap();
            } elseif (isset($this->joins[$shortLeftName])) {
                $previousJoin = $this->joins[$shortLeftName];
                $entityMap = $previousJoin->getEntityMap();
            } else {
                throw new PropelException('Unknown entity or alias ' . $leftName);
            }
        }
        $leftEntityAlias = isset($this->aliases[$leftName]) ? $leftName : null;

        // find the RelationMap in the EntityMap using the $relationName
        if (!$entityMap->hasRelation($relationName)) {
            throw new UnknownRelationException(sprintf('Unknown relation %s on the %s entity.', $relationName, $leftName));
        }
        $relationMap = $entityMap->getRelation($relationName);

        // create a ModelJoin object for this join
        $join = new ModelJoin();
        $join->setJoinType($joinType);
        if (null !== $previousJoin) {
            $join->setPreviousJoin($previousJoin);
        }
        $join->setRelationMap($relationMap, $leftEntityAlias, $relationAlias);

        // add the ModelJoin to the current object
        if (null !== $relationAlias) {
            $this->addAlias($relationAlias, $relationMap->getRightEntity()->getName());
            $this->addJoinObject($join, $relationAlias);
        } else {
            $this->addJoinObject($join, $relationName);
        }

        return $this;
    }

    /**
     * Add another condition to an already added join
     * @example
     * <code>
     * $query->join('Book.Author');
     * $query->addJoinCondition('Author', 'Book.Title LIKE ?', 'foo%');
     * </code>
     *
     * @param string $name     The relation name or alias on which the join was created
     * @param string $clause   SQL clause, may contain field and entity phpNames
     * @param mixed  $value    An optional value to bind to the clause
     * @param string $operator The operator to use to add the condition. Defaults to 'AND'
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function addJoinCondition($name, $clause, $value = null, $operator = null, $bindingType = null)
    {
        if (!isset($this->joins[$name])) {
            throw new PropelException(sprintf('Adding a condition to a nonexistent join, %s. Try calling join() first.', $name));
        }
        $join = $this->joins[$name];
        if (!$join->getJoinCondition() instanceof AbstractCriterion) {
            $join->buildJoinCondition($this);
        }
        $criterion = $this->getCriterionForClause($clause, $value, $bindingType);
        $method = Criteria::LOGICAL_OR === $operator ? 'addOr' : 'addAnd';
        $join->getJoinCondition()->$method($criterion);

        return $this;
    }

    /**
     * Replace the condition of an already added join
     * @example
     * <code>
     * $query->join('Book.Author');
     * $query->condition('cond1', 'Book.AuthorId = Author.Id')
     * $query->condition('cond2', 'Book.Title LIKE ?', 'War%')
     * $query->combine(array('cond1', 'cond2'), 'and', 'cond3')
     * $query->setJoinCondition('Author', 'cond3');
     * </code>
     *
     * @param string $name      The relation name or alias on which the join was created
     * @param mixed  $condition A Criterion object, or a condition name
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function setJoinCondition($name, $condition)
    {
        if (!isset($this->joins[$name])) {
            throw new PropelException(sprintf('Setting a condition to a nonexistent join, %s. Try calling join() first.', $name));
        }

        if ($condition instanceof AbstractCriterion) {
            $this->getJoin($name)->setJoinCondition($condition);
        } elseif (isset($this->namedCriterions[$condition])) {
            $this->getJoin($name)->setJoinCondition($this->namedCriterions[$condition]);
        } else {
            throw new PropelException(sprintf('Cannot add condition %s on join %s. setJoinCondition() expects either a Criterion, or a condition added by way of condition()', $condition, $name));
        }

        return $this;
    }

    /**
     * Add a join object to the Criteria
     * @see Criteria::addJoinObject()
     * @param Join $join A join object
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function addJoinObject(Join $join, $name = null)
    {
        if (!in_array($join, $this->joins)) { // compare equality, NOT identity
            if (null === $name) {
                $this->joins[] = $join;
            } else {
                $this->joins[$name] = $join;
            }
        }

        return $this;
    }

    /**
     * Adds a JOIN clause to the query and hydrates the related objects
     * Shortcut for $c->join()->with()
     * <code>
     *   $c->joinWith('Book.Author');
     *    => $c->join('Book.Author');
     *    => $c->with('Author');
     *   $c->joinWith('Book.Author a', Criteria::RIGHT_JOIN);
     *    => $c->join('Book.Author a', Criteria::RIGHT_JOIN);
     *    => $c->with('a');
     * </code>
     *
     * @param string $relation Relation to use for the join
     * @param string $joinType Accepted values are null, 'left join', 'right join', 'inner join'
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function joinWith($relation, $joinType = Criteria::INNER_JOIN)
    {
        $relationMap = $this->getEntityMap()->getRelation($relation);
        $this->join($relation, $joinType);
        $this->with(self::getRelationName($relation));

        return $this;
    }

    /**
     * Adds a relation to hydrate together with the main object
     * The relation must be initialized via a join() prior to calling with()
     * Examples:
     * <code>
     *   $c->join('Book.Author');
     *   $c->with('Author');
     *
     *   $c->join('Book.Author a', Criteria::RIGHT_JOIN);
     *   $c->with('a');
     * </code>
     * WARNING: on a one-to-many relationship, the use of with() combined with limit()
     * will return a wrong number of results for the related objects
     *
     * @param string $relation Relation to use for the join
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function with($relation)
    {
        //$relation = strtolower($relation);

        if (!isset($this->joins[$relation])) {
            throw new UnknownRelationException('Unknown relation name or alias ' . $relation);
        }

        $join = $this->joins[$relation];
        if (RelationMap::MANY_TO_MANY === $join->getRelationMap()->getType()) {
            throw new PropelException(__METHOD__ .' does not allow hydration for many-to-many relationships');
        } elseif (RelationMap::ONE_TO_MANY === $join->getRelationMap()->getType()) {
            // For performance reasons, the formatters will use a special routine in this case
            $this->isWithOneToMany = true;
        }

        // check that the fields of the main class are already added (but only if this isn't a useQuery)
        if (!$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectFields();
        }
        // add the fields of the related class
        $this->addRelationSelectFields($relation);

        // list the join for later hydration in the formatter
        $this->with[$relation] = new ModelWith($join);

        return $this;
    }

    public function isWithOneToMany()
    {
        return $this->isWithOneToMany;
    }

    /**
     * Adds a supplementary field to the select clause
     * These fields can later be retrieved from the hydrated objects using getVirtualField()
     *
     * @param string $clause The SQL clause with object model field names
     *                       e.g. 'UPPER(Author.FirstName)'
     * @param string $name   Optional alias for the added field
     *                       If no alias is provided, the clause is used as a field alias
     *                       This alias is used for retrieving the field via BaseObject::getVirtualField($alias)
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function withField($clause, $name = null)
    {
        if (null === $name) {
            $name = str_replace(array('.', '(', ')'), '', $clause);
        }

        $clause = trim($clause);
        $this->reamames($clause);
        // check that the fields of the main class are already added (if this is the primary ModelCriteria)
        if (!$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectFields();
        }
        $this->addAsField($name, $clause);

        return $this;
    }

    /**
     * Initializes a secondary ModelCriteria object, to be later merged with the current object
     *
     * @see ModelCriteria::endUse()
     * @param string $relationName           Relation name or alias
     * @param string $secondaryEntityClass   Full entity name of the secondary entity to be used to retrieve the query
     *
     * @return ModelCriteria The secondary criteria object
     */
    public function useQuery($relationName, $secondaryEntityClass = null)
    {
        if (!isset($this->joins[$relationName])) {
            throw new PropelException('Unknown class or alias ' . $relationName);
        }

        /** @var ModelJoin $join */
        $join = $this->joins[$relationName];
        $className = $join->getEntityMap()->getName();

        if (null === $secondaryEntityClass) {
            /** @var ModelCriteria $secondaryCriteria */
            $secondaryCriteria = $join->getEntityMap()->getRepository()->createQuery();
        } else {
            /** @var ModelCriteria $secondaryCriteria */
            $secondaryCriteria = $this->getConfiguration()->getRepository($secondaryEntityClass)->createQuery();
        }

        if ($className !== $relationName) {
            $secondaryCriteria->setEntityAlias($relationName, $relationName == $join->getRelationMap()->getName() ? false : true);
        }

        $secondaryCriteria->setPrimaryCriteria($this, $this->joins[$relationName]);

        return $secondaryCriteria;
    }

    /**
     * Finalizes a secondary criteria and merges it with its primary Criteria
     *
     * @see Criteria::mergeWith()
     *
     * @return ModelCriteria The primary criteria object
     */
    public function endUse()
    {
        if (isset($this->aliases[$this->entityAlias])) {
            $this->removeAlias($this->entityAlias);
        }

        $primaryCriteria = $this->getPrimaryCriteria();
        $primaryCriteria->mergeWith($this);

        return $primaryCriteria;
    }

    /**
     * Add the content of a Criteria to the current Criteria
     * In case of conflict, the current Criteria keeps its properties
     * @see Criteria::mergeWith()
     *
     * @param Criteria $criteria The criteria to read properties from
     * @param string   $operator The logical operator used to combine conditions
     *                           Defaults to Criteria::LOGICAL_AND, also accepts Criteria::LOGICAL_OR
     *
     * @return $this|ModelCriteria The primary criteria object
     */
    public function mergeWith(Criteria $criteria, $operator = null)
    {
        parent::mergeWith($criteria, $operator);

        // merge with
        if ($criteria instanceof ModelCriteria) {
            $this->with = array_merge($this->getWith(), $criteria->getWith());
        }

        return $this;
    }

    /**
     * Clear the conditions to allow the reuse of the query object.
     * The ModelCriteria's Model and alias 'all the properties set by construct) will remain.
     *
     * @return $this|ModelCriteria The primary criteria object
     */
    public function clear()
    {
        parent::clear();

        $this->with = array();
        $this->primaryCriteria = null;
        $this->formatter=null;

        return $this;
    }
    /**
     * Sets the primary Criteria for this secondary Criteria
     *
     * @param ModelCriteria $criteria     The primary criteria
     * @param Join          $previousJoin The previousJoin for this ModelCriteria
     */
    public function setPrimaryCriteria(ModelCriteria $criteria, Join $previousJoin)
    {
        $this->primaryCriteria = $criteria;
        $this->setPreviousJoin($previousJoin);
    }

    /**
     * Gets the primary criteria for this secondary Criteria
     *
     * @return ModelCriteria The primary criteria
     */
    public function getPrimaryCriteria()
    {
        return $this->primaryCriteria;
    }

    /**
     * Adds a Criteria as subQuery in the From Clause.
     *
     * @see Criteria::addSelectQuery()
     *
     * @param Criteria $subQueryCriteria         Criteria to build the subquery from
     * @param string   $alias                    alias for the subQuery
     * @param boolean  $addAliasAndSelectFields Set to false if you want to manually add the aliased select fields
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function addSelectQuery(Criteria $subQueryCriteria, $alias = null, $addAliasAndSelectFields = true)
    {
        if (!$subQueryCriteria->hasSelectClause()) {
            $subQueryCriteria->addSelfSelectFields();
        }

        parent::addSelectQuery($subQueryCriteria, $alias);

        if ($addAliasAndSelectFields) {
            // give this query-model same alias as subquery
            if (null === $alias) {
                end($this->selectQueries);
                $alias = key($this->selectQueries);
            }
            $this->setEntityAlias($alias, true);
            // so we can add selfSelectFields
            $this->addSelfSelectFields();
        }

        return $this;
    }

    /**
     * Adds the select fields for a the current entity
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function addSelfSelectFields()
    {
        $this->getEntityMap()->addSelectFields($this, $this->useAliasInSQL ? $this->entityAlias : null);

        return $this;
    }

    /**
     * Adds the select fields for a relation
     *
     * @param string $relation The relation name or alias, as defined in join()
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function addRelationSelectFields($relation)
    {
        //$relation = strtolower($relation);

        $join = $this->joins[$relation];
        $join->getEntityMap()->addSelectFields($this, $join->getRelationAlias());

        return $this;
    }

    /**
     * Returns the class and alias of a string representing a model or a relation
     * e.g. 'Book b' => array('Book', 'b')
     * e.g. 'Book'   => array('Book', null)
     *
     * @param string $class The classname to explode
     *
     * @return array list($className, $aliasName)
     */
    public static function getClassAndAlias($class)
    {
        if (false !== strpos($class, ' ')) {
            list($class, $alias) = explode(' ', $class);
        } else {
            $alias = null;
        }
        if (0 === strpos($class, '\\')) {
            $class = substr($class, 1);
        }

        return array($class, $alias);
    }

    /**
     * Returns the name of a relation from a string.
     * The input looks like '$leftName.$relationName $relationAlias'
     *
     * @param  string $relation Relation to use for the join
     * @return string the relationName used in the join
     */
    public static function getRelationName($relation)
    {
        // get the relationName
        list($fullName, $relationAlias) = self::getClassAndAlias($relation);
        if ($relationAlias) {
            $relationName = $relationAlias;
        } elseif (false === strpos($fullName, '.')) {
            $relationName = $fullName;
        } else {
            list(, $relationName) = explode('.', $fullName);
        }

        return $relationName;
    }

    /**
     * Triggers the automated cloning on termination.
     * By default, termination methods don't clone the current object,
     * even though they modify it. If the query must be reused after termination,
     * you must call this method prior to termination.
     *
     * @param boolean $isKeepQuery
     *
     * @return $this|ModelCriteria The current object, for fluid interface
     */
    public function keepQuery($isKeepQuery = true)
    {
        $this->isKeepQuery = (Boolean) $isKeepQuery;

        return $this;
    }

    /**
     * Checks whether the automated cloning on termination is enabled.
     *
     * @return boolean true if cloning must be done before termination
     */
    public function isKeepQuery()
    {
        return $this->isKeepQuery;
    }

    /**
     * Code to execute before every SELECT statement
     */
    protected function basePreSelect()
    {
        return $this->preSelect();
    }

    protected function preSelect()
    {
    }

    /**
     * Issue a SELECT query based on the current ModelCriteria
     * and format the list of results with the current formatter
     * By default, returns an array of model objects
     *
     * @return ObjectCollection|ActiveRecordInterface[]|array|mixed the list of results, formatted by the current formatter
     */
    public function find()
    {
        $this->basePreSelect();
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $dataFetcher = $criteria->doSelect();

        return $criteria
            ->getFormatter()
            ->init($criteria)
            ->format($dataFetcher);
    }

    /**
     * Issue a SELECT ... LIMIT 1 query based on the current ModelCriteria
     * and format the result with the current formatter
     * By default, returns a model object
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function findOne()
    {
        $this->basePreSelect();
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $criteria->limit(1);
        $dataFetcher = $criteria->doSelect();

        return $criteria
            ->getFormatter()
            ->init($criteria)
            ->formatOne($dataFetcher);
    }

    /**
     * Issue a SELECT ... LIMIT 1 query based on the current ModelCriteria
     * and format the result with the current formatter
     * By default, returns a model object
     *
     * @return mixed the result, formatted by the current formatter
     *
     * @throws PropelException
     */
    public function findOneOrCreate()
    {
        if ($this->joins) {
            throw new PropelException(__METHOD__ .' cannot be used on a query with a join, because Propel cannot transform a SQL JOIN into a subquery. You should split the query in two queries to avoid joins.');
        }

        if (!$ret = $this->findOne()) {
            $class = $this->getEntityName();
            $obj = new $class();
            foreach ($this->keys() as $key) {
                $obj->setByName(NamingTool::toUnderscore($key), $this->getValue($key), EntityMap::TYPE_FULLCOLNAME);
            }
            $ret = $this->getFormatter()->formatRecord($obj);
        }

        return $ret;
    }

    /**
     * Find object by primary key
     * Behaves differently if the model has simple or composite primary key
     * <code>
     * // simple primary key
     * $book  = $c->findPk(12, $con);
     * // composite primary key
     * $bookOpinion = $c->findPk(array(34, 634), $con);
     * </code>
     * @param mixed               $key Primary key to use for the query
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function findPk($key)
    {
        // As the query uses a PK condition, no limit(1) is necessary.
        $this->basePreSelect();
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $pkCols = array_values($this->getEntityMap()->getPrimaryKeys());
        if (1 === count($pkCols)) {
            // simple primary key
            $pkCol = $pkCols[0];
            $criteria->add($pkCol->getFullyQualifiedName(), $key);
        } else {
            // composite primary key
            foreach ($pkCols as $pkCol) {
                $keyPart = array_shift($key);
                $criteria->add($pkCol->getFullyQualifiedName(), $keyPart);
            }
        }
        $dataFetcher = $criteria->doSelect();

        return $criteria->getFormatter()->init($criteria)->formatOne($dataFetcher);
    }

    /**
     * Find objects by primary key
     * Behaves differently if the model has simple or composite primary key
     * <code>
     * // simple primary key
     * $books = $c->findPks(array(12, 56, 832), $con);
     * // composite primary key
     * $bookOpinion = $c->findPks(array(array(34, 634), array(45, 518), array(34, 765)), $con);
     * </code>
     * @param array               $keys Primary keys to use for the query
     *
     * @return mixed the list of results, formatted by the current formatter
     *
     * @throws PropelException
     */
    public function findPks($keys)
    {
        // As the query uses a PK condition, no limit(1) is necessary.
        $this->basePreSelect();
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $pkCols = $this->getEntityMap()->getPrimaryKeys();
        if (1 === count($pkCols)) {
            // simple primary key
            $pkCol = array_shift($pkCols);
            $criteria->add($pkCol->getFullyQualifiedName(), $keys, Criteria::IN);
        } else {
            // composite primary key
            throw new PropelException('Multiple object retrieval is not implemented for composite primary keys');
        }
        $dataFetcher = $criteria->doSelect();

        return $criteria->getFormatter()->init($criteria)->format($dataFetcher);
    }

    /**
     * Apply a condition on a field and issues the SELECT query
     *
     * @see filterBy()
     * @see find()
     *
     * @param string              $field A string representing the field phpName, e.g. 'AuthorId'
     * @param mixed               $value  A value for the condition
     *
     * @return mixed the list of results, formatted by the current formatter
     */
    public function findBy($field, $value)
    {
        $method = 'filterBy' . $field;
        $this->$method($value);

        return $this->find();
    }

    /**
     * Apply a list of conditions on fields and issues the SELECT query
     * <code>
     * $c->findByArray(array(
     *  'Title'     => 'War And Peace',
     *  'Publisher' => $publisher
     * ), $con);
     * </code>
     *
     * @see filterByArray()
     * @see find()
     *
     * @param mixed               $conditions An array of conditions, using field phpNames as key
     *
     * @return mixed the list of results, formatted by the current formatter
     */
    public function findByArray($conditions)
    {
        $this->filterByArray($conditions);

        return $this->find();
    }

    /**
     * Apply a condition on a field and issues the SELECT ... LIMIT 1 query
     *
     * @see filterBy()
     * @see findOne()
     *
     * @param mixed               $field A string representing the field phpName, e.g. 'AuthorId'
     * @param mixed               $value  A value for the condition
     *
     * @return mixed the result, formatted by the current formatter
     */
    public function findOneBy($field, $value)
    {
        $method = 'filterBy' . $field;
        $this->$method($value);

        return $this->findOne();
    }

    /**
     * Apply a list of conditions on fields and issues the SELECT ... LIMIT 1 query
     * <code>
     * $c->findOneByArray(array(
     *  'Title'     => 'War And Peace',
     *  'Publisher' => $publisher
     * ), $con);
     * </code>
     *
     * @see filterByArray()
     * @see findOne()
     *
     * @param mixed               $conditions An array of conditions, using field phpNames as key
     *
     * @return mixed the list of results, formatted by the current formatter
     */
    public function findOneByArray($conditions)
    {
        $this->filterByArray($conditions);

        return $this->findOne();
    }

    /**
     * Issue a SELECT COUNT(*) query based on the current ModelCriteria
     *
     * @return integer the number of results
     */
    public function count()
    {
        $this->basePreSelect();
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $criteria->setDbName($this->getDbName()); // Set the correct dbName
        $criteria->clearOrderByFields(); // ORDER BY won't ever affect the count

        // We need to set the primary entity name, since in the case that there are no WHERE fields
        // it will be impossible for the createSelectSql() method to determine which
        // entities go into the FROM clause.
        $criteria->setPrimaryEntityName($this->getEntityMap()->getFullClassName());

        $dataFetcher = $criteria->doCount();
        if ($row = $dataFetcher->fetch()) {
            $count = (int) current($row);
        } else {
            $count = 0; // no rows returned; we infer that means 0 matches.
        }
        $dataFetcher->close();

        return $count;
    }

    public function doCount()
    {
        $this->configureSelectFields();

        // check that the fields of the main class are already added (if this is the primary ModelCriteria)
        if (!$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectFields();
        }

        return parent::doCount();
    }

    /**
     * Issue a SELECT query based on the current ModelCriteria
     * and uses a page and a maximum number of results per page
     * to compute an offset and a limit.
     *
     * @param int                 $page       number of the page to start the pager on. Page 1 means no offset
     * @param int                 $maxPerPage maximum number of results per page. Determines the limit
     *
     * @return PropelModelPager a pager object, supporting iteration
     */
    public function paginate($page = 1, $maxPerPage = 10)
    {
        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $pager = new PropelModelPager($criteria, $maxPerPage);
        $pager->setPage($page);
        $pager->init();

        return $pager;
    }

    /**
     * Code to execute before every DELETE statement
     *
     * @param bool $withEvents
     */
    protected function basePreDelete($withEvents = false)
    {
        return $this->preDelete($withEvents);
    }

    /**
     * @param bool $withEvents
     *
     * @return boolean
     */
    protected function preDelete($withEvents = false)
    {
        return 0;
    }

    /**
     * Code to execute after every DELETE statement
     *
     * @param int                 $affectedRows the number of deleted rows
     */
    protected function basePostDelete($affectedRows)
    {
        $this->postDelete($affectedRows);
    }

    /**
     * @param $affectedRows
     */
    protected function postDelete($affectedRows)
    {
    }

    /**
     * Issue a DELETE query based on the current ModelCriteria
     * An optional hook on basePreDelete() can prevent the actual deletion
     *
     * @param bool $withEvents
     *
     * @throws PropelException
     * @return integer the number of deleted rows
     *
     */
    public function delete($withEvents = false)
    {
        if (0 === count($this->getMap())) {
            throw new PropelException(__METHOD__ .' expects a Criteria with at least one condition. Use deleteAll() to delete all the rows of a entity');
        }

        $con = $this->getConfiguration()->getConnectionManager($this->getDbName())->getWriteConnection();

        $criteria = $this->isKeepQuery() ? clone $this : $this;
        $criteria->setDbName($this->getDbName());

        try {
            return $con->transaction(function () use ($con, $criteria, $withEvents) {

                $event = null;
                if ($this->getEntityMap() && $withEvents) {
                    $eventQuery = clone $this;
                    $entities = $eventQuery
                        ->setEntityAlias(null)
                        ->setFormatter(static::FORMAT_OBJECT)
                        ->find();

                    $event = new DeleteEvent($this->getConfiguration()->getSession(), $this->getEntityMap(), $entities);
                    $this->getConfiguration()->getEventDispatcher()->dispatch(Events::PRE_DELETE, $event);
                }

                if (!$affectedRows = $criteria->basePreDelete($con)) {
                    $affectedRows = $criteria->doDelete($con);
                }
                $criteria->basePostDelete($affectedRows, $con);

                if ($event) {
                    $this->getConfiguration()->getEventDispatcher()->dispatch(Events::DELETE, $event);
                }

                return $affectedRows;
            });
        } catch (PropelException $e) {
            throw new PropelException(__METHOD__  . ' is unable to delete. ', 0, $e);
        }
    }

    /**
     * Issue a DELETE query based on the current ModelCriteria deleting all rows in the entity
     * An optional hook on basePreDelete() can prevent the actual deletion
     *
     * @param bool $withEvents
     *
     * @throws PropelException
     * @return integer the number of deleted rows
     */
    public function deleteAll($withEvents = false)
    {
        $con = $this->getConfiguration()->getConnectionManager($this->getDbName())->getWriteConnection();

        try {
            return $con->transaction(function () use ($con, $withEvents) {

                $event = null;
                if ($this->getEntityMap() && $withEvents) {
                    $eventQuery = clone $this;
                    $entities = $eventQuery
                        ->setEntityAlias(null)
                        ->setFormatter(static::FORMAT_OBJECT)
                        ->find();

                    $event = new DeleteEvent($this->getConfiguration()->getSession(), $this->getEntityMap(), $entities);
                    $this->getConfiguration()->getEventDispatcher()->dispatch(Events::PRE_DELETE, $event);
                }

                if (!$affectedRows = $this->basePreDelete($withEvents)) {
                    $affectedRows = $this->doDeleteAll();
                }
                $this->basePostDelete($affectedRows, $con);

                if ($event) {
                    $this->getConfiguration()->getEventDispatcher()->dispatch(Events::DELETE, $event);
                }

                return $affectedRows;
            });
        } catch (PropelException $e) {
            throw new PropelException(__METHOD__  . ' is unable to delete all. ', 0, $e);
        }
    }

    /**
     * Issue a DELETE query based on the current ModelCriteria deleting all rows in the entity
     * This method is called by ModelCriteria::deleteAll() inside a transaction
     *
     * @return integer the number of deleted rows
     *
     * @throws RuntimeException
     */
    public function doDeleteAll()
    {
        $con = $this->getConfiguration()->getConnectionManager($this->getDbName())->getWriteConnection();

        // join are not supported with DELETE statement
        if (count($this->getJoins())) {
            throw new RuntimeException('Delete does not support join');
        }

        $this->setPrimaryEntityName($this->getEntityMap()->getFullClassName());
        $entityName = $this->getPrimaryEntityName();

        $affectedRows = 0; // initialize this in case the next loop has no iterations.

        try {
            $entityName = $this->quoteTableIdentifierForEntity($entityName);
            $sql = "DELETE FROM " . $entityName;
            $this->getConfiguration()->debug("delete-all-sql: $sql");
            $stmt = $con->prepare($sql);

            $stmt->execute();

            $affectedRows += $stmt->rowCount();
        } catch (\Exception $e) {
            Propel::log($e->getMessage(), Propel::LOG_ERR);
            throw new RuntimeException(sprintf('Unable to execute DELETE ALL statement [%s]', $sql), 0, $e);
        }

        return $affectedRows;
    }

    /**
     * Code to execute before every UPDATE statement
     *
     * @param array   $values The associative array of fields and values for the update
     * @param boolean $withEvents
     *
     * @return integer
     */
    protected function basePreUpdate(&$values, $withEvents = false)
    {
        return $this->preUpdate($values, $withEvents);
    }

    /**
     * @param      $values
     * @param bool $withEvents
     *
     * @return boolean
     */
    protected function preUpdate(&$values, $withEvents = false)
    {
    }

    /**
     * Code to execute after every UPDATE statement
     *
     * @param int $affectedRows the number of updated rows
     */
    protected function basePostUpdate($affectedRows)
    {
        $this->postUpdate($affectedRows);
    }

    /**
     * @param integer $affectedRows
     */
    protected function postUpdate($affectedRows)
    {
    }

    /**
     * Issue an UPDATE query based the current ModelCriteria and a list of changes.
     * An optional hook on basePreUpdate() can prevent the actual update.
     * Beware that behaviors based on hooks in the object's save() method
     * will only be triggered if you force individual saves, i.e. if you pass true as second argument.
     *
     * @param mixed   $values     Associative array of keys and values to replace
     * @param boolean $withEvents Executes the query as SELECT and processed with its results SAVE/UPDATE events. This may be performance intensive.
     *
     * @return integer Number of updated rows
     *
     * @throws \Propel\Runtime\Exception\PropelException
     * @throws \Exception|\Propel\Runtime\Exception\PropelException
     */
    public function update($values, $withEvents = false)
    {
        if (!is_array($values) && !($values instanceof Criteria)) {
            throw new PropelException(__METHOD__ .' expects an array or Criteria as first argument');
        }

        if (count($this->getJoins())) {
            throw new PropelException(__METHOD__ .' does not support multientity updates, please do not use join()');
        }

        $con = $this->getConfiguration()->getConnectionManager($this->getDbName())->getWriteConnection();

        $criteria = $this->isKeepQuery() ? clone $this : $this;

        return $con->transaction(function () use ($con, $values, $criteria, $withEvents) {

            $event = null;
            if ($this->getEntityMap()) {
                $criteria->setPrimaryEntityName($this->getEntityMap()->getFullClassName());

                if ($withEvents) {
                    $eventQuery = clone $this;
                    $updates = $eventQuery
                        ->setEntityAlias(null)
                        ->setFormatter(static::FORMAT_OBJECT)
                        ->find();
                    $event = new SaveEvent($this->getConfiguration()->getSession(), $this->getEntityMap(), [], $updates);
                    $this->getConfiguration()->getEventDispatcher()->dispatch(Events::PRE_SAVE, $event);
                }
            }

            if (!$affectedRows = $criteria->basePreUpdate($values, $con, $withEvents)) {
                $affectedRows = $criteria->doUpdate($values, $con, $withEvents);
            }
            $criteria->basePostUpdate($affectedRows, $con);

            if ($event) {
                $this->getConfiguration()->getEventDispatcher()->dispatch(Events::SAVE, $event);
            }
            return $affectedRows;
        });
    }

    /**
     * Issue an UPDATE query based on the current ModelCriteria and a list of changes.
     * This method is called by ModelCriteria::update() inside a transaction.
     *
     * @param array   $values     Associative array of keys and values to replace
     * @param boolean $withEvents
     *
     * @return integer Number of updated rows
     */
    public function doUpdate($values, $withEvents = false)
    {
        // update rows in a single query
        if ($values instanceof Criteria) {
            $set = $values;
        } else {
            $set = new Criteria($this->getDbName());
            foreach ($values as $fieldName => $value) {
                $realFieldName = $this->getEntityMap()->getField($fieldName)->getFullyQualifiedName();
                $set->add($realFieldName, $value);
            }
        }

        $affectedRows = parent::doUpdate($set);
        if ($this->getEntityMap()->extractPrimaryKey($this)) {
            // this criteria updates only one object defined by a concrete primary key,
            // therefore there's no need to remove anything from the pool
        } else {
            $this->getConfiguration()->getSession()->clearFirstLevelCache($this->getEntityMap()->getFullClassName());
        }

        return $affectedRows;
    }

    /**
     * Creates a Criterion object based on a list of existing condition names and a comparator
     *
     * @param array  $conditions The list of condition names, e.g. array('cond1', 'cond2')
     * @param string $operator   An operator, Criteria::LOGICAL_AND (default) or Criteria::LOGICAL_OR
     *
     * @return AbstractCriterion A Criterion or ModelCriterion object
     */
    protected function getCriterionForConditions($conditions, $operator = null)
    {
        $operator = (null === $operator) ? Criteria::LOGICAL_AND : $operator;
        $this->combine($conditions, $operator, 'propel_temp_name');
        $criterion = $this->namedCriterions['propel_temp_name'];
        unset($this->namedCriterions['propel_temp_name']);

        return $criterion;
    }

    /**
     * Creates a Criterion object based on a SQL clause and a value
     * Uses introspection to translate the field phpName into a fully qualified name
     *
     * @param string $clause The pseudo SQL clause, e.g. 'AuthorId = ?'
     * @param mixed  $value  A value for the condition
     *
     * @return AbstractCriterion a Criterion object
     */
    protected function getCriterionForClause($clause, $value, $bindingType = null)
    {
        $origin = $clause = trim($clause);
        if ($this->replaceNames($clause)) {
            // at least one field name was found and replaced in the clause
            // this is enough to determine the type to bind the parameter to
            $colMap = $this->replacedFields[0];
            $value = $this->convertValueForField($value, $colMap);
            $clauseLen = strlen($clause);
            if (null !== $bindingType) {
                return new RawModelCriterion($this, $clause, $colMap, $value, $this->currentAlias, $bindingType);
            }
            if (stripos($clause, 'IN ?') == $clauseLen - 4) {
                return new InModelCriterion($this, $clause, $colMap, $value, $this->currentAlias);
            }
            if (stripos($clause, 'LIKE ?') == $clauseLen - 6) {
                return new LikeModelCriterion($this, $clause, $colMap, $value, $this->currentAlias);
            }
            if (substr_count($clause, '?') > 1) {
                return new SeveralModelCriterion($this, $clause, $colMap, $value, $this->currentAlias);
            }

            return new BasicModelCriterion($this, $clause, $colMap, $value, $this->currentAlias);
        }
        // no field match in clause, must be an expression like '1=1'
        if (false !== strpos($clause, '?')) {
            if (null === $bindingType) {
                throw new PropelException(sprintf('Cannot determine the field to bind to the parameter in clause "%s".', $origin));
            }

            return new RawCriterion($this, $clause, $value, $bindingType);
        }

        return new CustomCriterion($this, $clause);
    }

    /**
     * Converts value for some field types
     *
     * @param  mixed     $value  The value to convert
     * @param  FieldMap $colMap The FieldMap object
     * @return mixed     The converted value
     */
    protected function convertValueForField($value, FieldMap $colMap)
    {
        if ($colMap->getType() == 'OBJECT' && is_object($value)) {
            if (is_array($value)) {
                $value = array_map('serialize', $value);
            } else {
                $value = serialize($value);
            }
        } elseif ('ARRAY' === $colMap->getType() && is_array($value)) {
            $value = '| ' . implode(' | ', $value) . ' |';
        } elseif ('ENUM' === $colMap->getType()) {
            if (is_array($value)) {
                $value = array_map(array($colMap, 'getValueSetKey'), $value);
            } else {
                $value = $colMap->getValueSetKey($value);
            }
        }

        return $value;
    }

    /**
     * Callback function to replace field names by their real name in a clause
     * e.g.  'Book.Title IN ?'
     *    => 'book.title IN ?'
     *
     * @param array $matches Matches found by preg_replace_callback
     *
     * @return string the field name replacement
     */
    protected function doReplaceNameInExpression($matches)
    {
        $key = $matches[0];

        list($field, $realFullFieldName) = $this->getFieldFromName($key);

        if ($field instanceof FieldMap) {
            $this->replacedFields[] = $field;
            $this->foundMatch = true;

            if (false !== strpos($key, '.')) {
                list($entityName, $fieldName) = explode('.', $key);
                list($realEntityName, $realFieldName) = explode('.', $realFullFieldName);
                if (isset($this->aliases[$entityName])) {
                    //don't replace a alias with their real entity name
                    return $this->quoteIdentifier($entityName.'.'.$realFieldName);
                }
            }

            return $this->quoteIdentifier($realFullFieldName);
        }

        return $this->quoteIdentifier($key);
    }

    /**
     * Finds a field and a SQL translation for a pseudo SQL field name
     * Respects entity aliases previously registered in a join() or addAlias()
     * Examples:
     * <code>
     * $c->getFieldFromName('Book.Title');
     *   => array($bookTitleFieldMap, 'book.title')
     * $c->join('Book.Author a')
     *   ->getFieldFromName('a.FirstName');
     *   => array($authorFirstNameFieldMap, 'a.first_name')
     * </code>
     *
     * @param string $phpName String representing the field name in a pseudo SQL clause, e.g. 'Book.Title'
     *
     * @return array List($fieldMap, $realColumnName)
     */
    protected function getFieldFromName($phpName, $failSilently = true)
    {
        if (false === strpos($phpName, '.')) {
            $prefix = $this->getModelAliasOrName();
        } else {
            // $prefix could be either class name or entity name
            list($prefix, $phpName) = explode('.', $phpName);
        }

        $joinName = strtolower($prefix);
        $shortClass = self::getShortName($joinName);

        if ($prefix === $this->getModelAliasOrName()) {
            // field of the Criteria's model
            $entityMap = $this->getEntityMap();
        } elseif ($prefix === $this->getModelShortName()) {
            // field of the Criteria's model
            $entityMap = $this->getEntityMap();
        } elseif ($this->getEntityMap() && $prefix == $this->getEntityMap()->getName()) {
            // field name from Criteria's entityMap
            $entityMap = $this->getEntityMap();
        } elseif (isset($this->joins[$joinName])) {
            // field of a relations's model
            $entityMap = $this->joins[$joinName]->getEntityMap();
        } elseif (isset($this->joins[$shortClass])) {
            // field of a relations's model
            $entityMap = $this->joins[$shortClass]->getEntityMap();
        } elseif ($this->hasSelectQuery($prefix)) {
            return $this->getFieldFromSubQuery($prefix, $phpName, $failSilently);
        } elseif ($failSilently) {
            return array(null, null);
        } else {
            throw new UnknownModelException(sprintf('Unknown model, alias or entity "%s"', $prefix));
        }

//        if ($entityMap->hasFieldByPhpName($phpName)) {
//            $field = $entityMap->getFieldByPhpName($phpName);
//            if (isset($this->aliases[$prefix])) {
//                $this->currentAlias = $prefix;
//                $realFieldName = $prefix . '.' . $field->getName();
//            } else {
//                $realFieldName = $field->getFullyQualifiedName();
//            }
//
//            return array($field, $realFieldName);
//        } else

        if ($entityMap->hasField($phpName)) {
            $field = $entityMap->getField($phpName);
            $realColumnName = $field->getFullyQualifierColumnName();

            return array($field, $realColumnName);
        } elseif (isset($this->asFields[$phpName])) {
            // aliased field
            return array(null, $phpName);
        } elseif ($failSilently) {
            return array(null, null);
        } else {
            throw new UnknownFieldException(sprintf('Unknown field "%s" on model, alias or entity "%s"', $phpName, $prefix));
        }
    }


    /**
     * Builds, binds and executes a SELECT query based on the current object.
     *
     * @return DataFetcherInterface A dataFetcher using the connection, ready to be fetched
     *
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function doSelect()
    {

        // check that the fields of the main class are already added (if this is the primary ModelCriteria)
        if (!$this->hasSelectClause() && !$this->getPrimaryCriteria()) {
            $this->addSelfSelectFields();
        }

        $this->configureSelectFields();

        return parent::doSelect();
    }

    public function configureSelectFields()
    {
        // We need to set the primary entity name, since in the case that there are no WHERE fields
        // it will be impossible for the createSelectSql() method to determine which
        // entities go into the FROM clause.
        if (!$this->getPrimaryEntityName()) {
            $this->setPrimaryEntityName($this->getEntityMap()->getFullClassName());
        }

        if (is_null($this->select)) {
            // leave early
            return;
        }

        // select() needs the PropelSimpleArrayFormatter if no formatter given
        if (is_null($this->formatter)) {
            $this->setFormatter('\Propel\Runtime\Formatter\SimpleArrayFormatter');
        }

        // clear only the selectFields, clearSelectFields() clears asFields too
        $this->selectFields = array();

        // Add requested fields which are not withFields
        $fieldNames = is_array($this->select) ? $this->select : array($this->select);

        foreach ($fieldNames as $fieldName) {
            // check if the field was added by a withField, if not add it
            if (!array_key_exists($fieldName, $this->getAsFields())) {
                $field = $this->getFieldFromName($fieldName);
                // always put quotes around the fieldName to be safe, we strip them in the formatter
                $this->addAsField('"'.$fieldName.'"', $field[1]);
            }
        }
    }



    /**
     * Special case for subquery fields
     *
     * @return array List($fieldMap, $realFieldName)
     */
    protected function getFieldFromSubQuery($class, $phpName, $failSilently = true)
    {
        $subQueryCriteria = $this->getSelectQuery($class);
        $entityMap = $subQueryCriteria->getEntityMap();
        if ($entityMap->hasFieldByPhpName($phpName)) {
            $field = $entityMap->getFieldByPhpName($phpName);
            $realFieldName = $class.'.'.$field->getName();

            return array($field, $realFieldName);
        } elseif (isset($subQueryCriteria->asFields[$phpName])) {
            // aliased field
            return array(null, $class.'.'.$phpName);
        } elseif ($failSilently) {
            return array(null, null);
        } else {
            throw new PropelException(sprintf('Unknown field "%s" in the subQuery with alias "%s".', $phpName, $class));
        }
    }

    /**
     * Return a fully qualified field name corresponding to a simple field phpName
     * Uses model alias if it exists
     * Warning: restricted to the fields of the main model
     * e.g. => 'Title' => 'book.title'
     *
     * @param string $fieldName the Field phpName, without the entity name
     *
     * @return string the fully qualified field name
     */
    protected function getRealFieldName($fieldName)
    {
        if (!$this->getEntityMap()->hasField($fieldName)) {
            throw new UnknownFieldException('Unknown field ' . $fieldName . ' in model ' . $this->entityName);
        }

        if ($this->useAliasInSQL) {
            return $this->entityAlias . '.' . $this->getEntityMap()->getField($fieldName)->getName();
        }

        return $this
            ->getEntityMap()
            ->getField($fieldName)
            ->getFullyQualifiedName()
            ;
    }

    /**
     * Changes the entity part of a a fully qualified field name if a true model alias exists
     * e.g. => 'book.TITLE' => 'b.TITLE'
     * This is for use as first argument of Criteria::add()
     *
     * @param string $colName the fully qualified field name, e.g 'book.TITLE' or BookEntityMap::TITLE
     *
     * @return string the fully qualified field name, using entity alias if applicable
     */
    public function getAliasedColName($colName)
    {
        if ($this->useAliasInSQL) {
            return $this->entityAlias . substr($colName, strrpos($colName, '.'));
        }

        return $colName;
    }

    /**
     * Return the short ClassName for class with namespace
     *
     * @param string $fullyQualifiedClassName The fully qualified class name
     *
     * @return string The short class name
     */
    public static function getShortName($fullyQualifiedClassName)
    {
        $namespaceParts = explode('\\', $fullyQualifiedClassName);

        return array_pop($namespaceParts);
    }

    /**
     * Overrides Criteria::add() to force the use of a true entity alias if it exists
     *
     * @see Criteria::add()
     * @param string $field   The colName of field to run the condition on (e.g. BookEntityMap::ID)
     * @param mixed  $value
     * @param string $operator A String, like Criteria::EQUAL.
     *
     * @return $this|ModelCriteria A modified Criteria object.
     */
    public function addUsingAlias($field, $value = null, $operator = null)
    {
        return $this->addUsingOperator($this->getAliasedColName($field), $value, $operator);
    }

    /**
     * Get all the parameters to bind to this criteria
     * Does part of the job of createSelectSql() for the cache
     *
     * @return array list of parameters, each parameter being an array like
     *               array('entity' => $realentity, 'field' => $field, 'value' => $value)
     */
    public function getParams()
    {
        $params = array();
        $dbMap = $this->getConfiguration()->getDatabase($this->getDbName());

        foreach ($this->getMap() as $criterion) {

            $entity = null;
            foreach ($criterion->getAttachedCriterion() as $attachedCriterion) {
                $entityName = $attachedCriterion->getEntity();

                $entity = $this->getEntityForAlias($entityName);
                if (null === $entity) {
                    $entity = $entityName;
                }

                if (
                    ($this->isIgnoreCase() || method_exists($attachedCriterion, 'setIgnoreCase'))
                    && $dbMap->getEntity($entity)->getField($attachedCriterion->getField())->isText()
                ) {
                    $attachedCriterion->setIgnoreCase(true);
                }
            }

            $sb = '';
            $criterion->appendPsTo($sb, $params);
        }

        $having = $this->getHaving();
        if (null !== $having) {
            $sb = '';
            $having->appendPsTo($sb, $params);
        }

        return $params;
    }

    /**
     * Handle the magic
     * Supports findByXXX(), findOneByXXX(), filterByXXX(), orderByXXX(), and groupByXXX() methods,
     * where XXX is a field phpName.
     * Supports XXXJoin(), where XXX is a join direction (in 'left', 'right', 'inner')
     */
    public function __call($name, $arguments)
    {
        // Maybe it's a magic call to one of the methods supporting it, e.g. 'findByTitle'
        static $methods = array('findBy', 'findOneBy', 'filterBy', 'orderBy', 'groupBy');
        foreach ($methods as $method) {
            if (0 === strpos($name, $method)) {
                $fields = substr($name, strlen($method));
                if (in_array($method, array('findBy', 'findOneBy')) && strpos($fields, 'And') !== false) {
                    $method = $method . 'Array';
                    $fields = explode('And', $fields);
                    $conditions = array();
                    foreach ($fields as $field) {
                        $conditions[$field] = array_shift($arguments);
                    }
                    array_unshift($arguments, $conditions);
                } else {
                    array_unshift($arguments, $fields);
                }

                return call_user_func_array(array($this, $method), $arguments);
            }
        }

        // Maybe it's a magic call to a qualified joinWith method, e.g. 'leftJoinWith' or 'joinWithAuthor'
        if (false !== ($pos = stripos($name, 'joinWith'))) {
            $type = substr($name, 0, $pos);
            if (in_array($type, array('left', 'right', 'inner'))) {
                $joinType = strtoupper($type) . ' JOIN';
            } else {
                $joinType = Criteria::INNER_JOIN;
            }

            if (!$relation = substr($name, $pos + 8)) {
                $relation = $arguments[0];
            }

            return $this->joinWith($relation, $joinType);
        }

        // Maybe it's a magic call to a qualified join method, e.g. 'leftJoin'
        if (($pos = strpos($name, 'Join')) > 0) {
            $type = substr($name, 0, $pos);
            if (in_array($type, array('left', 'right', 'inner'))) {
                $joinType = strtoupper($type) . ' JOIN';
                // Test if first argument is supplied, else don't provide an alias to joinXXX (default value)
                if (!isset($arguments[0])) {
                    $arguments[0] = null;
                }
                array_push($arguments, $joinType);
                $method = lcfirst(substr($name, $pos));

                return call_user_func_array(array($this, $method), $arguments);
            }
        }

        throw new PropelException(sprintf('Undefined method %s::%s()', __CLASS__, $name));
    }

    /**
     * Ensures deep cloning of attached objects
     */
    public function __clone()
    {
        parent::__clone();

        foreach ($this->with as $key => $join) {
            $this->with[$key] = clone $join;
        }

        if (null !== $this->formatter) {
            $this->formatter = clone $this->formatter;
        }
    }
}
