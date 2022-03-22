<?php

namespace Leuchtturm\Utilities;

use Closure;
use GraphQL\Arguments\GraphQLFieldArgument;
use GraphQL\Errors\UnauthenticatedError;
use GraphQL\Fields\GraphQLTypeField;
use GraphQL\Types\GraphQLBoolean;
use GraphQL\Types\GraphQLInt;
use GraphQL\Types\GraphQLList;
use GraphQL\Types\GraphQLNonNull;
use GraphQL\Types\GraphQLString;
use GraphQL\Types\GraphQLType;
use http\Exception;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Leuchtturm\LeuchtturmException;
use ReflectionException;

class FieldFactory
{
    const CREATE = "CREATE";
    const READ = "READ";
    const UPDATE = "UPDATE";
    const DELETE = "DELETE";
    const ALL = "ALL";

    /**
     * Name of the GraphQLTypeField. E.g. "deleteUser".
     *
     * @var string
     */
    private string $name;

    /**
     * Pure name of the GraphQLTypeField. E.g. "user".
     *
     * @var string
     */
    private string $pureName;

    /**
     * Description of the GraphQLTypeField.
     *
     * @var string
     */
    private string $description;

    /**
     * DAO class name.
     *
     * @var string
     */
    private string $dao;

    /**
     * CRUD operation of the field.
     *
     * @var string
     */
    private string $operation;

    /**
     * Guardian that protects the field.
     *
     * @var string|null
     */
    private ?string $guardian = null;

    /**
     * Guardian that verifies the ownership and protects the field.
     *
     * @var string|null
     */
    private ?string $ownerGuardian = null;

    /**
     * Callback that is executed before the actual execution of the field resolver.
     *
     * @var Closure|null
     */
    private ?Closure $preExec = null;

    /**
     * Callback that is executed after the actual execution of the field resolver.
     *
     * @var Closure|null
     */
    private ?Closure $postExec = null;

    /**
     * TypeFactory for building type and input type of the GraphQLTypeField.
     *
     * @var TypeFactory
     */
    private TypeFactory $typeFactory;

    public function build(): GraphQLTypeField
    {
        // create field
        return new GraphQLTypeField(
            $this->name,
            $this->buildReturnType(),
            $this->description,
            $this->buildResolve(),
            $this->buildArgs()
        );
    }

    /**
     * Builds the return type for the field.
     *
     * @return GraphQLType
     * @throws LeuchtturmException
     * @throws ReflectionException
     */
    private function buildReturnType(): GraphQLType
    {
        return match ($this->operation) {
            FieldFactory::CREATE, FieldFactory::READ => $this->typeFactory->build(),
            FieldFactory::ALL => new GraphQLNonNull(new GraphQLList(new GraphQLNonNull($this->typeFactory->build()))),
            FieldFactory::UPDATE, FieldFactory::DELETE => new GraphQLNonNull(new GraphQLBoolean()),
        };
    }

    /**
     * Builds the return type for the field.
     *
     * @return GraphQLType
     * @throws LeuchtturmException
     * @throws ReflectionException
     */
    private function buildInputType(): GraphQLType
    {
        return $this->typeFactory->buildInput();
    }

    /**
     * Builds the resolve function for the field.
     *
     * @return Closure
     */
    private function buildResolve(): Closure
    {
        $this_ = $this;
        $dao = $this->dao;
        $pureName = $this->pureName;
        $hasOne = $this->typeFactory->getHasOne();
        $hasMany = $this->typeFactory->getHasMany();
        return match ($this->operation) {
            FieldFactory::CREATE => function ($parent, $args) use ($dao, $pureName, $hasMany, $hasOne) {
                // check permissions
                $this->validateRequestWithGuardian();

                // call preExec callback
                $this->callPre($args);

                // store ids to other relations
                $relationsToAddMany = [];
                foreach ($hasMany as $field => $value) {
                    if (array_key_exists($field, $args[$pureName])) {
                        $relationsToAddMany[$field] = $args[$pureName][$field];
                        unset($args[$pureName][$field]);
                    }
                }
                // add one to one or one to many relations as direct fieldsin args
                foreach ($hasOne as $field => $value) {
                    if (array_key_exists($field, $args[$pureName])) {
                        $args[$pureName][$field . "_id"] = $args[$pureName][$field];
                        unset($args[$pureName][$field]);
                    }
                }

                // remove null values
                foreach ($args[$pureName] as $key => $value) {
                    if ($value === null)
                        unset($args[$pureName][$key]);
                }

                // create the actual entry
                $entry = call_user_func("$dao::create", $args[$pureName]);

                // add relations
                foreach ($relationsToAddMany as $argument => $ids) {
                    if ($ids === null)
                        continue;
                    $relationship = $entry->{$argument}();
                    foreach ($ids as $id) {
                        if ($relationship instanceof HasMany)
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::get", $id));
                        if ($relationship instanceof BelongsToMany)
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::get", $id));
                    }
                }

                // call postExec callback
                $this->callPost($entry);

                return $entry;
            },
            FieldFactory::READ => function ($parent, $args) use ($dao) {
                // check permissions
                $this->validateRequestWithGuardian($args["id"]);

                // call preExec callback
                $this->callPre($args);

                // get entry
                $entry = call_user_func("$dao::get", $args["id"]);

                // call postExec callback
                $this->callPost($entry);

                return $entry;
            },
            FieldFactory::UPDATE => function ($parent, $args) use ($dao, $pureName, $hasMany, $hasOne) {
                // check permissions
                $this->validateRequestWithGuardian($args["id"]);

                // call preExec callback
                $this->callPre($args);

                // store ids to other relations
                $relationsToAddMany = [];
                $relationsToAddOne = [];
                foreach ($hasMany as $field => $value) {
                    if (array_key_exists($field, $args[$pureName])) {
                        $relationsToAddMany[$field] = $args[$pureName][$field];
                        unset($args[$pureName][$field]);
                    }
                }
                foreach ($hasOne as $field => $value) {
                    if (array_key_exists($field, $args[$pureName])) {
                        $relationsToAddOne[$field] = $args[$pureName][$field];
                        unset($args[$pureName][$field]);
                    }
                }

                // get entry
                $entry = call_user_func("$dao::get", $args["id"]);
                foreach ($args[$pureName] as $property => $value)
                    $entry->{$property} = $value;

                // update relations
                foreach ($relationsToAddMany as $argument => $ids) {
                    if($ids === null)
                        continue;

                    $relationship = $entry->{$argument}();

                    // remove old entries
                    if ($relationship instanceof HasMany) {
                        foreach ($ids as $id) {
                            $fkPropertyColumn = "{$pureName}_id";
                            $relatedEntry = call_user_func("{$hasMany[$argument]->getType()}::where", $fkPropertyColumn, $entry->id)
                                ->update([$fkPropertyColumn => null]);
                        }
                    }
                    if ($relationship instanceof BelongsToMany)
                        $relationship->detach();

                    // connect new entries
                    foreach ($ids as $id) {
                        if ($relationship instanceof HasMany) {
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::get", $id));
                        }
                        if ($relationship instanceof BelongsToMany)
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::get", $id));
                    }
                }

                foreach ($relationsToAddOne as $argument => $id) {
                    if($id === null)
                        continue;

                    $relationship = $entry->{$argument}();

                    // connect new entries
                    if ($relationship instanceof HasOne)
                        $relationship->save(call_user_func("{$hasOne[$argument]->getType()}::get", $id));
                    if ($relationship instanceof HasOneOrMany)
                        $relationship->save(call_user_func("{$hasOne[$argument]->getType()}::get", $id));
                }

                $success = $entry->update();

                // call postExec callback
                $this->callPost($entry, $success);

                return $success;
            },
            FieldFactory::DELETE => function ($parent, $args) use ($dao, $pureName) {
                // check permissions
                $this->validateRequestWithGuardian($args["id"]);

                // call preExec callback
                $this->callPre($args);

                // delete entry
                $entry = call_user_func("$dao::get", $args["id"]);
                $success = $entry->delete();

                // call postExec callback
                $this->callPost($entry, $success);

                return $success;
            },
            FieldFactory::ALL => function ($parent) use ($dao, $this_) {
                // check permissions
                $this->validateRequestWithGuardian();

                // call preExec callback
                $this->callPre();

                $entries = call_user_func("$dao::all");

                // call postExec callback
                $this->callPost($entries);

                return $entries;
            },
        };
    }

    /**
     * Builds the arguments for the field.
     *
     * @return array
     * @throws LeuchtturmException
     * @throws ReflectionException
     */
    private function buildArgs(): array
    {
        $dao = $this->dao;
        $pureName = $this->pureName;
        return match ($this->operation) {
            FieldFactory::ALL => [],
            FieldFactory::CREATE => [
                new GraphQLFieldArgument($pureName, new GraphQLNonNull($this->buildInputType()))
            ],
            FieldFactory::UPDATE => [
                new GraphQLFieldArgument("id", new GraphQLNonNull(new GraphQLInt())),
                new GraphQLFieldArgument($pureName, new GraphQLNonNull($this->buildInputType()))
            ],
            FieldFactory::READ, FieldFactory::DELETE => [
                new GraphQLFieldArgument("id", new GraphQLNonNull(new GraphQLInt()))
            ],
        };
    }

    /**
     * Calls the preExec callback.
     *
     * @return void
     */
    public function callPre()
    {
        if ($this->preExec !== null) {
            $fn = $this->preExec;
            return $fn(...func_get_args());
        }
    }

    /**
     * Calls the postExec callback.
     *
     * @return void
     */
    public function callPost()
    {
        if ($this->postExec !== null) {
            $fn = $this->postExec;
            return $fn(...func_get_args());
        }
    }

    /**
     * @param string $name
     * @return FieldFactory
     */
    public function name(string $name): FieldFactory
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @param string $description
     * @return FieldFactory
     */
    public function description(string $description): FieldFactory
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @param string $operation
     * @return FieldFactory
     */
    public function operation(string $operation): FieldFactory
    {
        $this->operation = $operation;
        return $this;
    }

    /**
     * @param TypeFactory $typeFactory
     * @return FieldFactory
     */
    public function typeFactory(TypeFactory $typeFactory): FieldFactory
    {
        $this->typeFactory = $typeFactory;
        return $this;
    }

    /**
     * @param string $dao
     * @return FieldFactory
     */
    public function dao(string $dao): FieldFactory
    {
        $this->dao = $dao;
        return $this;
    }

    /**
     * @param string $pureName
     * @return FieldFactory
     */
    public function pureName(string $pureName): FieldFactory
    {
        $this->pureName = strtolower($pureName);
        return $this;
    }

    /**
     * Sets the guardian that protects the route.
     *
     * @param string $name
     * @return $this
     */
    public function guardian(string $name = "default"): static
    {
        $this->guardian = $name;
        return $this;
    }

    /**
     * Ensures that the id of the logged-in user, provided by a guardian, equals
     * the id passed to the field "id"-parameter.
     *
     * @return $this
     */
    public function onlyOwner(string $guardianName = "default"): static
    {
        $this->ownerGuardian = $guardianName;
        return $this;
    }

    /**
     * Sets the callback that is executed before the actual execution of the field resolver.
     *
     * @param Closure $callback
     * @return $this
     */
    public function pre(Closure $callback): static
    {
        $this->preExec = $callback;
        return $this;
    }

    /**
     * Sets the callback that is executed after the actual execution of the field resolver.
     *
     * @param Closure $callback
     * @return $this
     */
    public function post(Closure $callback): static
    {
        $this->postExec = $callback;
        return $this;
    }

    /**
     * Validates the current request and throws a UnauthenticatedError-GraphQLError if
     * the request is not allowed to access this field.
     *
     * @throws UnauthenticatedError
     */
    private function validateRequestWithGuardian(mixed $id = null): void
    {
        throw new \Exception("NOT IMPLEMENTED");
        // check if guardian is set.
        if ($this->guardian !== null) {
            // check if guardian exists and request not valid against guardian.
            if (Auth::guardian($this->guardian) !== null
                && !Auth::guardian($this->guardian)->validate(app("request"))) {

                // if the guardian cannot verify the request, check if owner guardian is set.
                // if that is not the case, throw an UnauthenticatedError - but if it is, ensure
                // that the requestor is the owner of the entry.
                if ($this->ownerGuardian === null)
                    throw new UnauthenticatedError("Access denied");
                else if (Auth::guardian($this->ownerGuardian) !== null
                    && (
                        !Auth::guardian($this->ownerGuardian)->validate(app("request"))
                        || Auth::guardian($this->ownerGuardian)->user()?->getIdentifier() !== $id
                    ))
                    throw new UnauthenticatedError("Access denied");
            }
        } // else check if ownership guardian is set, exists, request is not valid or not the owner
        else if ($this->ownerGuardian !== null
            && Auth::guardian($this->ownerGuardian) !== null
            && (
                !Auth::guardian($this->ownerGuardian)->validate(app("request"))
                || Auth::guardian($this->ownerGuardian)->user()?->getIdentifier() !== $id
            ))
            throw new UnauthenticatedError("Access denied");
    }
}