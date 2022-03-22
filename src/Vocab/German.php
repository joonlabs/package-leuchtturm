<?php

namespace Leuchtturm\Vocab;

class German implements Vocab
{
    /**
     * Holds all exceptions with a custom plural form.
     *
     * @var array
     */
    private array $exceptions = [
        "job" => "jobs",
        "login" => "logins",
        "konto" => "konten",
        "pizza" => "pizzen",
        "kaktus" => "kakteen",
    ];

    public function __construct(array $exceptions = [])
    {
        $this->exceptions = array_merge($this->exceptions, $exceptions);
    }

    public function pluralize(string $word): string
    {
        if (array_key_exists($word, $this->exceptions))
            return $this->exceptions[$word];

        if (str_ends_with($word, "e")) return substr($word, 0) . "n";
        if (str_ends_with($word, "ent")) return substr($word, 0) . "en";
        if (str_ends_with($word, "and")) return substr($word, 0) . "en";
        if (str_ends_with($word, "ant")) return substr($word, 0) . "en";
        if (str_ends_with($word, "ist")) return substr($word, 0) . "en";
        if (str_ends_with($word, "or")) return substr($word, 0) . "en";
        if (str_ends_with($word, "in")) return substr($word, 0) . "en";
        if (str_ends_with($word, "ion")) return substr($word, 0) . "en";
        if (str_ends_with($word, "ik")) return substr($word, 0) . "en";
        if (str_ends_with($word, "heit")) return substr($word, 0) . "en";
        if (str_ends_with($word, "keit")) return substr($word, 0) . "en";
        if (str_ends_with($word, "schaft")) return substr($word, 0) . "en";
        if (str_ends_with($word, "tät")) return substr($word, 0) . "en";
        if (str_ends_with($word, "ung")) return substr($word, 0) . "en";
        if (str_ends_with($word, "ma")) return substr($word, 0, -1) . "en";
        if (str_ends_with($word, "um")) return substr($word, 0, -2) . "en";
        if (str_ends_with($word, "us")) return substr($word, 0) . "en";
        if (str_ends_with($word, "eur")) return substr($word, 0) . "e";
        if (str_ends_with($word, "ich")) return substr($word, 0) . "e";
        if (str_ends_with($word, "ier")) return substr($word, 0) . "e";
        if (str_ends_with($word, "ig")) return substr($word, 0) . "e";
        if (str_ends_with($word, "ling")) return substr($word, 0) . "e";
        if (str_ends_with($word, "ör")) return substr($word, 0) . "e";
        if (str_ends_with($word, "nd")) return substr($word, 0) . "e";
        if (str_ends_with($word, "a")) return substr($word, 0) . "s";
        if (str_ends_with($word, "i")) return substr($word, 0) . "s";
        if (str_ends_with($word, "o")) return substr($word, 0) . "s";
        if (str_ends_with($word, "u")) return substr($word, 0) . "s";
        if (str_ends_with($word, "y")) return substr($word, 0) . "s";
        if (str_ends_with($word, "aub")) return substr($word, 0, -3) . "aeube";
        if (str_ends_with($word, "ub")) return substr($word, 0, -2) . "uebe";
        if (str_ends_with($word, "ob")) return substr($word, 0, -2) . "oebe";
        if (str_ends_with($word, "ab")) return substr($word, 0, -2) . "aebe";
        if (str_ends_with($word, "eb")) return substr($word, 0, -2) . "ebe";
        return $word;
    }

    public function operationC(string $word): string
    {
        return "erstelle" . ucwords($word);
    }

    public function operationR(string $word): string
    {
        return lcfirst($word);
    }

    public function operationU(string $word): string
    {
        return "bearbeite" . ucwords($word);
    }

    public function operationD(string $word): string
    {
        return "loesche" . ucwords($word);
    }

    public function operationA(string $word): string
    {
        return "alle" . ucwords($this->pluralize($word));
    }
}