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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Facades\Auth;
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
     * Scopes that needed to be present in the requests token to access the field.
     *
     * @var string|null
     */
    private array $scopes = [];

    /**
     * Scopes that needed to be present in the requests token to access the field while the requsting user
     * must be the identify of the ressource.
     *
     * @var array
     */
    private array $identityScopes = [];

    /**
     * Property of the authenticated user model that indicates the identity with the ressource.
     * @var string
     */
    private string $identityProperty = "id";

    /**
     * The column / property in the model that referecnes the auhenticated user and should be used for identity
     * checks on request validation.
     *
     * @var string
     */
    private string $identityColumn = "user_id";

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
                $this->validateAuthorization();

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
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::find", $id));
                        if ($relationship instanceof BelongsToMany)
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::find", $id));
                    }
                }

                // call postExec callback
                $this->callPost($entry);

                return $entry;
            },
            FieldFactory::READ => function ($parent, $args) use ($dao) {
                // check permissions
                $this->validateAuthorization($args["id"]);

                // call preExec callback
                $this->callPre($args);

                // get entry
                $entry = call_user_func("$dao::find", $args["id"]);

                // call postExec callback
                $this->callPost($entry);

                return $entry;
            },
            FieldFactory::UPDATE => function ($parent, $args) use ($dao, $pureName, $hasMany, $hasOne) {
                // check permissions
                $this->validateAuthorization($args["id"]);

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
                $entry = call_user_func("$dao::find", $args["id"]);
                foreach ($args[$pureName] as $property => $value)
                    $entry->{$property} = $value;

                // update relations
                foreach ($relationsToAddMany as $argument => $ids) {
                    if ($ids === null)
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
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::find", $id));
                        }
                        if ($relationship instanceof BelongsToMany)
                            $relationship->save(call_user_func("{$hasMany[$argument]->getType()}::find", $id));
                    }
                }

                foreach ($relationsToAddOne as $argument => $id) {
                    if ($id === null)
                        continue;

                    $relationship = $entry->{$argument}();

                    // connect new entries
                    if ($relationship instanceof HasOne)
                        $relationship->save(call_user_func("{$hasOne[$argument]->getType()}::find", $id));
                    if ($relationship instanceof BelongsTo)
                        $relationship->associate(call_user_func("{$hasOne[$argument]->getType()}::find", $id));
                }

                $success = $entry->update();

                // call postExec callback
                $this->callPost($entry, $success);

                return $success;
            },
            FieldFactory::DELETE => function ($parent, $args) use ($dao, $pureName) {
                // check permissions
                $this->validateAuthorization($args["id"]);

                // call preExec callback
                $this->callPre($args);

                // delete entry
                $entry = call_user_func("$dao::find", $args["id"]);
                $success = $entry->delete();

                // call postExec callback
                $this->callPost($entry, $success);

                return $success;
            },
            FieldFactory::ALL => function ($parent) use ($dao, $this_) {
                // check permissions
                $this->validateAuthorization();

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
     * Sets the scopes of which at least one is required to access the resource.
     *
     * @param string $name
     * @return $this
     */
    public function scopes(array $scopes): static
    {
        $this->scopes = $scopes;
        return $this;
    }

    /**
     * Sets the scopes of which at least one is required to access the resource while additionaly ensuring
     * that the authenticated user is the identity of the ressource.
     *
     * @return $this
     */
    public function identityScopes(array $scopes, $identityProperty = "id"): static
    {
        $this->identityScopes = $scopes;
        $this->identityProperty = $identityProperty;
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
    private function validateAuthorization(mixed $identifier = null): void
    {
        // check for scopes or identity scopes
        if (!empty($this->scopes)) {
            // if the request matches the scopes the execution can continue
            if ($this->requestMatchesScopes())
                return;

            // if not, we have to ensure the identity scopes exist, otherwise we encounter an authentication error
            if (empty($this->identityScopes))
                // throw unauthenticated error if no scope matched
                throw new UnauthenticatedError(
                    "Access denied. Missing one of the following scopes: [" .
                    implode(", ", array_merge($this->scopes, $this->identityScopes)) . "]");

            // check if any identity scope matches
            if ($this->requestMatchesIdentityScopes($identifier)) {
                return;
            }

            // throw unauthenticated error, as no identity scope matched
            throw new UnauthenticatedError(
                "Access denied. Requestor cannot prove identity and ownership of ressource entry.");
        }

        // check for identity scopes only
        if (!empty($this->identityScopes)) {
            // check if any identity scope matches
            if ($this->requestMatchesIdentityScopes($identifier)) {
                return;
            }

            // throw unauthenticated error, as no identity scope matched
            throw new UnauthenticatedError(
                "Access denied. Requestor cannot prove identity and ownership of ressource entry.");
        }
    }

    /**
     * Returns whether the request is allowed by the identity scopes.
     *
     * @return bool
     */
    private function requestMatchesScopes(): bool
    {
        // iterate over all scopes. if any scope matches, return true.
        foreach ($this->scopes as $scope) {
            if (request()->user("api")?->tokenCan($scope))
                return true;
        }

        // no identity scope matched
        return false;
    }

    /**
     * Returns whether the request is allowed by the identity scopes.
     *
     * @return bool
     */
    private function requestMatchesIdentityScopes(mixed $identifier = null): bool
    {
        // check if any scope matches
        $matchedAnyScope = false;

        // iterate over all identity scopes. if any scope matches, set $matchedAnyScope to true
        foreach ($this->identityScopes as $identityScope) {
            $matchedAnyScope |= request()->user("api")?->tokenCan($identityScope);
        }

        // if any scope matched, check if the requestor is the actual owner
        if ($matchedAnyScope && Auth::guard("api")->user()->{$this->identityProperty} === $identifier)
            return true;

        // no identity scope matched
        return false;
    }
}