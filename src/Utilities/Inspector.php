<?php

namespace Leuchtturm\Utilities;

use GraphQL\Fields\GraphQLTypeField;
use GraphQL\Types\GraphQLBoolean;
use GraphQL\Types\GraphQLFloat;
use GraphQL\Types\GraphQLInt;
use GraphQL\Types\GraphQLList;
use GraphQL\Types\GraphQLNonNull;
use GraphQL\Types\GraphQLString;
use GraphQL\Types\GraphQLType;
use Leuchtturm\Utilities\Reflection\ReflectionProperty;
use ReflectionException;
use Tests\Resources\Classes\User;

class Inspector
{
    /**
     * Cache of all inspections run.
     * @var array|array[]
     */
    private static array $cache = [
        "classDoc" => [],
        "constructor" => []
    ];

    /**
     * @throws ReflectionException
     */
    static function getProperties(string $class)
    {
        // check if class is cached
        if (array_key_exists($class, self::$cache["constructor"]))
            return self::$cache["constructor"][$class];

        // obtain parameters and properties
        $parameters = [];
        $properties = [];
        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getProperties() as $property) {
            if(!$property->isPrivate())
                $properties[$property->getName()] = $property;
        }

        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        // create \Leuchtturm\Utilities\Reflection\ReflectionProperty for the properties
        return array_map(function ($property) use ($parameters) {
            $hasDefaultValue = $property->hasDefaultValue();
            $defaultValue = $property->getDefaultValue();
            if (!$hasDefaultValue && array_key_exists($property->getName(), $parameters)) {
                $hasDefaultValue = $parameters[$property->getName()]->isDefaultValueAvailable();
                if ($hasDefaultValue)
                    $defaultValue = $parameters[$property->getName()]->getDefaultValue();
            }

            return (new ReflectionProperty())
                ->setName($property->getName())
                ->setType($property->getType())
                ->setHasDefaultValue($hasDefaultValue)
                ->setDefaultValue($defaultValue);
        }, $properties);
    }

    /**
     * @throws ReflectionException
     */
    static function getPropertiesFromClassDoc(string $class)
    {
        // check if class is cached
        if (array_key_exists($class, self::$cache["classDoc"]))
            return self::$cache["classDoc"][$class];

        // obtain parameters and properties
        $reflection = new \ReflectionClass($class);

        $doc = $reflection->getDocComment();
        if ($doc === false)
            return [];

        $properties = static::parsePropertiesFromClassDoc($doc);
        $properties = static::checkForArrayTypeInProperties($properties);

        // create \Leuchtturm\Utilities\Reflection\ReflectionProperty for the properties
        $properties = array_map(function ($property) {
            return (new ReflectionProperty())
                ->setName($property["name"])
                ->setType($property["type"])
                ->setKind($property["kind"])
                ->setHasDefaultValue(false)
                ->setIsArrayType($property["isArray"])
                ->addGuardian($property["guardians"]);
        }, $properties);

        return static::fullQualifyProperties($properties, $reflection->getNamespaceName());
    }

    /**
     * Takes parsed properties and adds the full qualified name.
     *
     * @param array $properties
     * @return array
     */
    private static function checkForArrayTypeInProperties(array $properties): array
    {
        foreach ($properties as &$property) {
            $property["isArray"] = str_ends_with($property["type"], "[]");
            if ($property["isArray"]) {
                $property["type"] = substr($property["type"], 0, -2);
            }
        }
        return $properties;
    }

    /**
     * Takes parsed properties and adds the full qualified name.
     *
     * @param array $properties
     * @param string $namespace
     * @return array
     */
    private static function fullQualifyProperties(array $properties, string $namespace): array
    {
        foreach ($properties as &$property) {
            // qualify type
            if (!$property->isPrimitiveType() && !str_contains($property->getType(), "\\")) {
                $prefix = $property->isNullable() ? "?" : "";
                $property->setType($prefix . $namespace . "\\" . $property->getType());
            }
        }
        return $properties;
    }

    /**
     * Parses a doc string and returns an AST for the properties.
     *
     * @param string $doc
     * @return array
     */
    private static function parsePropertiesFromClassDoc(string $doc): array
    {
        $lines = explode("\n", $doc);

        $textProperties = [];

        // match properties
        foreach ($lines as $line) {
            $regex = '/ ?\*? ?@property(-read|-write)? (\??(\\\\?([A-Z]|[a-z]|_)+)+(\[\])?) (\$([A-Z]|[a-z]|_)+)/m';

            preg_match_all($regex, $line, $matches, PREG_PATTERN_ORDER, 0);

            if (!empty($matches[0])) {
                $kind = match($matches[1][0]){
                    "-read" => ReflectionProperty::KIND_READ,
                    "-write" => ReflectionProperty::KIND_WRITE,
                    default => ReflectionProperty::KIND_DEFAULT
                };

                $textProperties[] = [
                    "kind" => $kind,
                    "type" => $matches[2][0],
                    "name" => substr($matches[6][0], 1),
                    "guardians" => []
                ];
            }
        }

        // match guardians
        foreach ($lines as $line) {
            $regex = '/ ?\*? ?@protect (\$([A-Z]|[a-z]|_)+) (\??(\\\\?([A-Z]|[a-z]|_)+)+)/m';

            preg_match_all($regex, $line, $matches, PREG_PATTERN_ORDER, 0);

            if (!empty($matches[0])) {
                $name = substr($matches[1][0], 1);
                foreach($textProperties as &$property){
                    if($property["name"] === $name)
                        $property["guardians"][] = $matches[3][0];
                }
            }
        }

        return $textProperties;
    }
}