<?php

namespace Tests\Unit\Models;

use EA_Model;
use Tests\TestCase;

require_once APPPATH . 'core/EA_Model.php';

final class EAModelQuoteOrderByTest extends TestCase
{
    public function testQuoteOrderByReturnsEmptyStringForMissingOrderBy(): void
    {
        $model = new class extends EA_Model {};

        $this->assertSame('', $model->quote_order_by(null));
        $this->assertSame('', $model->quote_order_by(''));
        $this->assertSame('', $model->quote_order_by(" \t\n"));
    }

    public function testQuoteOrderByQuotesProvidedColumns(): void
    {
        $model = new class extends EA_Model {};

        $this->assertSame(
            '`first_name` ASC, `last_name` DESC',
            $model->quote_order_by('first_name ASC,last_name DESC'),
        );
    }
}
