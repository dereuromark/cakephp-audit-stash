<?php

declare(strict_types=1);

namespace TestApp\Model\Enum;

enum InvoiceTypeEnum: string
{
    case Anzahl = 'Anzahlungsrechnung';
    case Schluss = 'Schlussrechnung';
}
