<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

// Truncation instead of transactions: fixture DDL commits implicitly.
uses(TestCase::class, DatabaseTruncation::class)->in('Feature', 'MailTruth');
uses(TestCase::class)->in('Unit');
