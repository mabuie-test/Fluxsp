<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

final class DocxAssemblyService
{
    public function assemble(string $title, array $sections, string $destination): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addTitle($title, 1);

        foreach ($sections as $item) {
            $section->addText((string) $item);
            $section->addTextBreak();
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($destination);

        return $destination;
    }
}
