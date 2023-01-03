<?php

namespace Leuchtturm\Facades;

use GraphQL\Types\GraphQLType;
use Illuminate\Support\Facades\Facade;
use Leuchtturm\LeuchtturmManager;
use Leuchtturm\Utilities\FieldFactory;
use Leuchtturm\Utilities\TypeFactory;
use Leuchtturm\Vocab\Vocab;

/**
 * @method static LeuchtturmManager setVocab(Vocab $vocab)
 * @method static LeuchtturmManager registerFilter(string $name, callable $filter)
 * @method static TypeFactory create(string $dao, ?string $typename = null)
 * @method static GraphQLType build(string $dao)
 * @method static TypeFactory factory(string $dao)
 * @method static FieldFactory C(string $dao, ?string $fieldname = null, string $description = "")
 * @method static FieldFactory R(string $dao, ?string $fieldname = null, string $description = "")
 * @method static FieldFactory U(string $dao, ?string $fieldname = null, string $description = "")
 * @method static FieldFactory D(string $dao, ?string $fieldname = null, string $description = "")
 * @method static FieldFactory A(string $dao, ?string $fieldname = null, string $description = "")
 * @see \Leuchtturm\LeuchtturmManager
 */
class Leuchtturm extends Facade{

    protected static function getFacadeAccessor(): string
    {
        return "Leuchtturm";
    }
}