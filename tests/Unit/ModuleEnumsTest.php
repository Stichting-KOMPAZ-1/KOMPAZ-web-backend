<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ContentBlockType;
use App\Enums\ModuleStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModuleEnumsTest extends TestCase
{
    #[Test]
    public function a_module_status_is_stored_by_name_and_shown_in_dutch(): void
    {
        // The stored values are in the database and will be in every JSON response, so changing
        // one is a migration rather than a rename. The labels are the product's and are free to be
        // reworded without either.
        $this->assertSame(['Available', 'InDevelopment'], ModuleStatus::values());

        $this->assertSame([
            'Available' => 'Beschikbaar',
            'InDevelopment' => 'In ontwikkeling',
        ], ModuleStatus::options());
    }

    #[Test]
    public function a_content_block_type_is_stored_by_name_and_shown_in_dutch(): void
    {
        // These three strings are written into the table's check constraints as well, so they are
        // not renameable from PHP alone.
        $this->assertSame(['Text', 'Image', 'Video'], ContentBlockType::values());

        $this->assertSame([
            'Text' => 'Tekst',
            'Image' => 'Afbeelding',
            'Video' => 'Video',
        ], ContentBlockType::options());
    }
}
