<?php

namespace Leuchtturm;


use GraphQL\Arguments\GraphQLFieldArgument;
use GraphQL\Fields\GraphQLTypeField;
use GraphQL\Types\GraphQLBoolean;
use GraphQL\Types\GraphQLInt;
use GraphQL\Types\GraphQLNonNull;
use GraphQL\Types\GraphQLObjectType;
use Leuchtturm\Utilities\FieldFactory;
use Leuchtturm\Utilities\TypeFactory;
use Leuchtturm\Vocab\Vocab;
use ReflectionException;

class LeuchtturmManager
{
    /**
     * Holds all types under their typenames.
     *
     * @var TypeFactory[]
     */
    public array $types = [];

    /**
     * Holds the vocabulary.
     *
     * @var Vocab
     */
    public Vocab $vocab;

    /**
     * @param Vocab $vocab
     * @return LeuchtturmManager
     */
    public function setVocab(Vocab $vocab): LeuchtturmManager
    {
        $this->vocab = $vocab;
        return $this;
    }

    /**
     * Creates a new TypeFactory.
     *
     * @param string $dao
     * @param string|null $typename
     * @return TypeFactory
     */
    public function create(string $dao, ?string $typename = null): TypeFactory
    {
        return $this->types[$dao] = (new TypeFactory($this))
            ->setDAO($dao)
            ->setName($typename ?? $this->getShortNameForClass($dao));
    }

    /**
     * Builds a GraphQLObjectType instance.
     *
     * @param string $dao
     * @return GraphQLObjectType
     * @throws ReflectionException
     * @throws LeuchtturmException
     */
    public function build(string $dao): GraphQLObjectType
    {
        $this->createIfNotExists($dao);
        return $this->types[$dao]->build();
    }

    /**
     * Returns the TypeFactory for a typename.
     *
     * @param string $dao
     * @return TypeFactory
     */
    public function factory(string $dao): TypeFactory
    {
        $this->createIfNotExists($dao);
        return $this->types[$dao];
    }

    /**
     * @throws ReflectionException
     */
    private function getFieldNameForClass(string $dao): string
    {
        return $this->getShortNameForClass($dao);
    }

    /**
     * @throws ReflectionException
     */
    private function getShortNameForClass(string $dao): string
    {
        return (new \ReflectionClass($dao))->getShortName();
    }

    private function createIfNotExists(string $dao)
    {
        if (!array_key_exists($dao, $this->types))
            $this->create($dao);
    }

    /**
     * @throws ReflectionException
     */
    public function C(string $dao, ?string $fieldname = null, string $description = ""): FieldFactory
    {
        // create type if not exists
        $this->createIfNotExists($dao);

        return (new FieldFactory())
            ->operation(FieldFactory::CREATE)
            ->name($fieldname ?? $this->vocab->operationC($this->getFieldNameForClass($dao)))
            ->pureName($this->getFieldNameForClass($dao))
            ->description($description)
            ->typeFactory($this->factory($dao))
            ->dao($dao);
    }

    /**
     * @throws ReflectionException
     */
    public function R(string $dao, ?string $fieldname = null, string $description = ""): FieldFactory
    {
        // create type if not exists
        $this->createIfNotExists($dao);

        return (new FieldFactory())
            ->operation(FieldFactory::READ)
            ->name($fieldname ?? $this->vocab->operationR($this->getFieldNameForClass($dao)))
            ->pureName($this->getFieldNameForClass($dao))
            ->description($description)
            ->typeFactory($this->factory($dao))
            ->dao($dao);
    }

    /**
     * @throws ReflectionException
     */
    public function U(string $dao, ?string $fieldname = null, string $description = ""): FieldFactory
    {
        // create type if not exists
        $this->createIfNotExists($dao);

        return (new FieldFactory())
            ->operation(FieldFactory::UPDATE)
            ->name($fieldname ?? $this->vocab->operationU($this->getFieldNameForClass($dao)))
            ->pureName($this->getFieldNameForClass($dao))
            ->description($description)
            ->typeFactory($this->factory($dao))
            ->dao($dao);
    }

    /**
     * @throws ReflectionException
     */
    public function D(string $dao, ?string $fieldname = null, string $description = ""): FieldFactory
    {
        // create type if not exists
        $this->createIfNotExists($dao);

        return (new FieldFactory())
            ->operation(FieldFactory::DELETE)
            ->name($fieldname ?? $this->vocab->operationD($this->getFieldNameForClass($dao)))
            ->pureName($this->getFieldNameForClass($dao))
            ->description($description)
            ->typeFactory($this->factory($dao))
            ->dao($dao);
    }

    /**
     * @throws ReflectionException
     */
    public function A(string $dao, ?string $fieldname = null, string $description = ""): FieldFactory
    {
        // create type if not exists
        $this->createIfNotExists($dao);

        return (new FieldFactory())
            ->operation(FieldFactory::ALL)
            ->name($fieldname ?? $this->vocab->operationA($this->getFieldNameForClass($dao)))
            ->pureName($this->getFieldNameForClass($dao))
            ->description($description)
            ->typeFactory($this->factory($dao))
            ->dao($dao);
    }
}