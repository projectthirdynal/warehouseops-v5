<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

uses(
    TestCase::class,
    DatabaseTransactions::class,
)->in('Feature');
