<?php

namespace Leuchtturm\Utilities\Reflection;

class ReflectionProperty
{
    const KIND_READ = "READ";
    const KIND_WRITE = "WRITE";
    const KIND_DEFAULT = "DEFAULT";

    /**
     * Name of the property.
     *
     * @var string
     */
    private string $name = "";

    /**
     * Type of the property.
     *
     * @var string
     */
    private string $type = "";

    /**
     * Kind of the property (default, read or write).
     *
     * @var string
     */
    private string $kind = ReflectionProperty::KIND_DEFAULT;

    /**
     * Determines wether the property has a default value.
     *
     * @var bool
     */
    private bool $hasDefaultValue = false;

    /**
     * The property's default value.
     *
     * @var mixed
     */
    private mixed $defaultValue;

    /**
     * Guardians that protect the property.
     *
     * @var array
     */
    private array $guardians = [];

    /**
     * Determines wether the property is an array type.
     *
     * @var bool
     */
    private bool $isArrayType = false;


    /**
     * @return bool
     */
    public function isNullable(): bool
    {
        return str_starts_with($this->type, "?");
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return ReflectionProperty
     */
    public function setName(string $name): ReflectionProperty
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return ltrim($this->type, "?");
    }

    /**
     * @return bool
     */
    public function isPrimitiveType(): bool
    {
        return match($this->getType()){
            "int", "bool", "string", "float" => true,
            default  => false
        };
    }

    /**
     * @param string $type
     * @return ReflectionProperty
     */
    public function setType(string $type): ReflectionProperty
    {
        if(str_starts_with($this->getType(), "?"))
            $this->type = "?$type";
        else
            $this->type = "$type";
        return $this;
    }

    /**
     * @return bool
     */
    public function hasDefaultValue(): bool
    {
        return $this->hasDefaultValue;
    }

    /**
     * @param bool $hasDefaultValue
     * @return ReflectionProperty
     */
    public function setHasDefaultValue(bool $hasDefaultValue): ReflectionProperty
    {
        $this->hasDefaultValue = $hasDefaultValue;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    /**
     * @param mixed $defaultValue
     * @return ReflectionProperty
     */
    public function setDefaultValue(mixed $defaultValue): ReflectionProperty
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    /**
     * @return bool
     */
    public function isArrayType(): bool
    {
        return $this->isArrayType;
    }

    /**
     * @param bool $isArrayType
     * @return ReflectionProperty
     */
    public function setIsArrayType(bool $isArrayType): static
    {
        $this->isArrayType = $isArrayType;
        return $this;
    }

    /**
     * Adds a guardian that protects the property.
     *
     * @param string|array $guardian
     * @return $this
     */
    public function addGuardian(string|array $guardian): static
    {
        if (is_string($guardian))
            $guardian = [$guardian];
        $this->guardians = $guardian;
        return $this;
    }

    /**
     * Returns all guardians of the property.
     *
     * @return array
     */
    public function getGuardians(): array
    {
        return $this->guardians;
    }

    /**
     * Returns whether the property has guardians.
     *
     * @return bool
     */
    public function hasGuardians(): bool
    {
        return !empty($this->guardians);
    }

    /**
     * Sets the property kind.
     *
     * @param string $kind
     * @return ReflectionProperty
     */
    public function setKind(string $kind): ReflectionProperty
    {
        $this->kind = $kind;
        return $this;
    }

    /**
     * Returns the property kind.
     *
     * @return string
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Returns wether the property is writable.
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        return match ($this->getKind()) {
            ReflectionProperty::KIND_READ => false,
            default => true
        };
    }

    /**
     * Returns wether the property is readable.
     *
     * @return bool
     */
    public function isReadable(): bool
    {
        return match ($this->getKind()) {
            ReflectionProperty::KIND_WRITE => false,
            default => true
        };
    }
}