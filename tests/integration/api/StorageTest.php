<?php

namespace ErnestDefoe\Armory\Tests\integration\api;

use ErnestDefoe\Armory\ArmoryBattlenetAccount;
use ErnestDefoe\Armory\BattlenetAuth;
use ErnestDefoe\Armory\Briefing;
use ErnestDefoe\Armory\Crafting;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\Paths;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;

/** Token encryption, briefing pinning and the crafting roster read. */
class StorageTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-armory');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Last week', 'user_id' => 1, 'comment_count' => 1, 'created_at' => '2026-10-20 00:00:00'],
                ['id' => 2, 'title' => 'This week', 'user_id' => 1, 'comment_count' => 1, 'created_at' => '2026-10-27 00:00:00'],
            ],
            'armory_characters' => [
                ['id' => 1, 'user_id' => 2, 'region' => 'us', 'realm_slug' => 'area-52', 'name' => 'Frostbolt', 'class' => 'Mage', 'is_visible' => true, 'is_main' => true],
                ['id' => 2, 'user_id' => 2, 'region' => 'us', 'realm_slug' => 'area-52', 'name' => 'Hidden', 'is_visible' => false],
            ],
        ]);
    }

    private function auth(?string $storage = null): BattlenetAuth
    {
        $this->app();
        $container = $this->app()->getContainer();
        if ($storage === null) {
            return $container->make(BattlenetAuth::class);
        }
        $default = $container->make(Paths::class);

        return $container->make(BattlenetAuth::class, ['paths' => new Paths([
            'base' => $default->base, 'public' => $default->public, 'storage' => $storage,
        ])]);
    }

    #[Test]
    public function a_linked_token_is_stored_encrypted()
    {
        $auth = $this->auth();

        $this->assertTrue($auth->storeLink(2, ['access_token' => 'secret-token'], ['sub' => '1001']));

        $stored = ArmoryBattlenetAccount::query()->where('user_id', 2)->value('access_token');
        $this->assertStringStartsWith('sb1:', $stored);
        $this->assertStringNotContainsString('secret-token', $stored);
        $this->assertSame('secret-token', $auth->decrypt($stored));
    }

    #[Test]
    public function a_token_is_never_stored_in_plaintext_when_it_cannot_be_encrypted()
    {
        $auth = $this->auth('/nonexistent-armory-storage');

        $this->assertFalse($auth->storeLink(2, ['access_token' => 'secret-token'], ['sub' => '1001']));
        $this->assertFalse(ArmoryBattlenetAccount::query()->where('user_id', 2)->exists());
    }

    #[Test]
    public function a_new_briefing_is_pinned_and_the_previous_one_unpinned()
    {
        $this->setting('armory.briefing_last_discussion_id', '1');
        $this->app();
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $schema = $db->getSchemaBuilder();
        if (! $schema->hasColumn('discussions', 'is_sticky')) {
            // What flarum/sticky adds; it isn't installed in this test suite.
            $schema->table('discussions', fn (Blueprint $t) => $t->boolean('is_sticky')->default(false));
        }
        $db->table('discussions')->where('id', 1)->update(['is_sticky' => true]);

        $briefing = $this->app()->getContainer()->make(Briefing::class);
        (fn () => $this->applyPinning(2))->call($briefing);

        $this->assertSame([1 => 0, 2 => 1], $db->table('discussions')->pluck('is_sticky', 'id')->map(fn ($v) => (int) $v)->all());
    }

    #[Test]
    public function the_crafting_roster_lists_only_visible_characters_with_their_owner()
    {
        $this->app();
        $crafting = $this->app()->getContainer()->make(Crafting::class);

        $chars = (fn () => $this->chars())->call($crafting);

        $this->assertCount(1, $chars);
        $this->assertSame('Frostbolt', $chars[0]->name);
        $this->assertSame('normal', $chars[0]->username);
        $this->assertSame(2, $chars[0]->user_id);
    }
}
