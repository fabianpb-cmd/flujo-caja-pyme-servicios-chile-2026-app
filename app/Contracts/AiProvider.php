<?php

namespace App\Contracts;

interface AiProvider
{
    /** @return array{status:string,answer:string,source_ids:array,required_information?:array,warnings?:array} */
    public function ask(array $context): array;
}
