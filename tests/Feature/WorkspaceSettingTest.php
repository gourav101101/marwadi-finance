<?php

namespace Tests\Feature;

use App\Models\WorkspaceSetting;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_keep_their_singleton_id_after_the_sequence_advances(): void
    {
        WorkspaceSetting::forceCreate(['id' => 50, 'name' => 'Temporary'])->delete();
        $settings = WorkspaceSetting::current();
        $this->assertSame(1, $settings->id);
        $settings->update(['payment_name' => 'Saved payee']);
        $this->assertSame('Saved payee', WorkspaceSetting::current()->payment_name);
        $this->assertSame(1, WorkspaceSetting::count());
    }

    public function test_admin_can_save_and_reload_valid_bank_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/settings', [
            'name' => 'Example Wealth',
            'payment_name' => 'Example Account Holder',
            'bank_account_number' => '001234567890',
            'ifsc_code' => 'abcd0123456',
            'upi_id' => 'example@bank',
            'payment_note' => 'Fictional test instructions.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $settings = WorkspaceSetting::current();
        $this->assertSame('Example Account Holder', $settings->payment_name);
        $this->assertSame('001234567890', $settings->bank_account_number);
        $this->assertSame('ABCD0123456', $settings->ifsc_code);
        $this->assertSame('example@bank', $settings->upi_id);
        $this->assertSame('Fictional test instructions.', $settings->payment_note);

        $this->get('/admin/settings')
            ->assertOk()
            ->assertSee('Account Holder Name')
            ->assertSee('name="bank_account_number" type="text" value="001234567890"', false)
            ->assertSee('name="ifsc_code" type="text" value="ABCD0123456"', false);
    }

    public function test_bank_details_require_matching_fields_and_valid_indian_formats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/settings', [
            'name' => 'Example Wealth',
            'payment_name' => '',
            'bank_account_number' => '12345',
            'ifsc_code' => 'NOT-AN-IFSC',
        ])->assertSessionHasErrors(['payment_name', 'bank_account_number', 'ifsc_code']);

        $this->post('/admin/settings', [
            'name' => 'Example Wealth',
            'payment_name' => 'Example Account Holder',
            'bank_account_number' => '001234567890',
            'ifsc_code' => '',
        ])->assertSessionHasErrors('ifsc_code');

        $this->assertSame(0, WorkspaceSetting::count());
    }

    public function test_clients_cannot_update_workspace_bank_details(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        Client::create([
            'user_id' => $user->id,
            'code' => 'TEST1A',
            'phone' => '2025550100',
            'risk' => 'Low',
            'investment' => 0,
            'details' => ['first_name' => 'Test'],
        ]);

        $this->actingAs($user)->post('/admin/settings', [
            'name' => 'Unauthorized Change',
            'payment_name' => 'Unauthorized Holder',
            'bank_account_number' => '001234567890',
            'ifsc_code' => 'ABCD0123456',
        ])->assertForbidden();

        $this->assertSame(0, WorkspaceSetting::count());
    }
}
