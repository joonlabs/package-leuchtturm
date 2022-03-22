<?php

namespace Leuchtturm\Vocab;

interface Vocab
{
    public function pluralize(string $word): string;

    public function operationC(string $word): string;
    public function operationR(string $word): string;
    public function operationU(string $word): string;
    public function operationD(string $word): string;
    public function operationA(string $word): string;
}