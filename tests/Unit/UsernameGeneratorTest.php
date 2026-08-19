<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_simple_username_first()
    {
        $username = UsernameGenerator::generate('Steven Wicca Alfredo');
        $this->assertEquals('steven', $username);
    }

    public function test_it_generates_with_initials_if_taken()
    {
        User::factory()->create(['username' => 'steven']);

        $username = UsernameGenerator::generate('Steven Wicca Alfredo');
        $this->assertEquals('stevenw', $username); // first + 1st initial
    }

    public function test_it_generates_with_more_initials_if_taken()
    {
        User::factory()->create(['username' => 'steven']);
        User::factory()->create(['username' => 'stevenw']);

        $username = UsernameGenerator::generate('Steven Wicca Alfredo');
        $this->assertEquals('stevenwa', $username); // first + all initials
    }

    public function test_it_generates_combined_parts_if_initials_taken()
    {
        User::factory()->create(['username' => 'steven']);
        User::factory()->create(['username' => 'stevenw']);
        User::factory()->create(['username' => 'stevenwa']);

        $username = UsernameGenerator::generate('Steven Wicca Alfredo');
        // Candidates: steven, stevenw, stevenwa, stwial (2-letter combined), alfredos (reverse), stevenwiccaalfredo
        $this->assertEquals('stwial', $username);
    }

    public function test_it_falls_back_to_random_numbers()
    {
        // Mocking many users to force fallback is tedious,
        // let's just test if it returns a unique one when name is taken.
        User::factory()->create(['username' => 'steven']);
        User::factory()->create(['username' => 'stevenw']);
        User::factory()->create(['username' => 'stevenwa']);
        User::factory()->create(['username' => 'stewicalf']);
        User::factory()->create(['username' => 'stwial']);
        User::factory()->create(['username' => 'alfredos']);
        User::factory()->create(['username' => 'stevenwiccaa']); // from full slug limited

        $username = UsernameGenerator::generate('Steven Wicca Alfredo');

        $this->assertNotEquals('steven', $username);
        $this->assertTrue(strlen($username) <= 12);
        $this->assertMatchesRegularExpression('/[0-9]{2,4}$/', $username);
    }
}
