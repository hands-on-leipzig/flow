<?php

namespace Tests\Unit;

use App\Mail\Catalog\MailNotificationCatalog;
use Tests\TestCase;

class MailNotificationCatalogTest extends TestCase
{
    public function test_every_catalog_entry_renders_html(): void
    {
        config([
            'mail.from.address' => 'noreply@hands-on-technology.org',
            'mail.from.name' => 'FLOW',
        ]);

        $catalog = new MailNotificationCatalog;

        foreach ($catalog->all() as $definition) {
            $preview = $catalog->preview($definition->key);

            $this->assertNotSame('', $preview['subject'], $definition->key);
            $this->assertStringContainsString('<html', $preview['html'], $definition->key);
            $this->assertStringContainsString('data:image/png;base64,', $preview['html'], $definition->key);
            $this->assertStringContainsString('font-family:Uniform', $preview['html'], $definition->key);
            $this->assertStringNotContainsString('#111111', $preview['html'], $definition->key);
        }
    }
}
