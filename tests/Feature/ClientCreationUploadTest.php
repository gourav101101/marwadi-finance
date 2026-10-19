<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\TestCase;

class ClientCreationUploadTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return [
            'first_name' => 'Mira', 'middle_name' => 'Test', 'surname' => 'Shah', 'code' => 'FULL1001',
            'email' => 'full@example.test', 'phone' => '9000000001', 'risk' => 'Medium',
            'investment' => '10000.25', 'password' => 'ClientTest123!', 'pan' => 'ABCDE1234F',
            'dob' => '1990-01-02', 'address' => 'Test address', 'registration' => 'REG100',
            'provide' => 'Test information', 'aadhaar' => '123456789012', 'bank_name' => 'Test Bank',
            'account_number' => '001234567890', 'account_holder' => 'Mira Test Shah',
            'ifsc' => 'ABCD0123456', 'account_type' => 'Savings', 'nominee' => 'Parent',
            'nominee_name' => 'Test Parent', 'nominee_pan' => 'FGHIJ5678K', 'nominee_aadhaar' => '234567890123',
            'photo' => UploadedFile::fake()->image('photo.png'),
            'signature' => UploadedFile::fake()->image('signature.jpg'),
            'aadhaar_document' => UploadedFile::fake()->createWithContent('aadhaar.pdf', "%PDF-1.4\n%%EOF"),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_complete_client_form_saves_all_details_and_documents_and_client_can_sign_in(): void
    {
        $data = $this->data();
        $this->post('/admin/clients', $data)->assertSessionHasNoErrors();
        $client = Client::sole();
        $this->assertSame(1000025, $client->investment);
        $this->assertSame('Mira Test Shah', $client->user->name);
        foreach (['dob', 'address', 'registration', 'provide', 'aadhaar', 'bank_name', 'account_number',
            'account_holder', 'ifsc', 'account_type', 'nominee', 'nominee_name', 'nominee_pan', 'nominee_aadhaar'] as $field) {
            $this->assertSame($data[$field], $client->details[$field]);
        }
        $this->assertSame(3, $client->documents()->count());
        $this->post('/logout');
        $this->post('/login', ['login' => $client->code, 'password' => $data['password']])->assertSessionHasNoErrors();
        $this->get('/profile')->assertOk()->assertSee('Test Bank')->assertSee('Test Parent');
        foreach ($client->documents as $document) {
            Storage::disk('local')->assertExists($document->path);
            $this->get('/documents/'.$document->id)->assertOk();
        }
    }

    public function test_saved_previews_render_and_pdf_preview_keeps_document_access_private(): void
    {
        $this->post('/admin/clients', $this->data())->assertSessionHasNoErrors();
        $client = Client::sole();
        $pdf = $client->documents()->where('kind', 'aadhaar')->sole();
        $previewUrl = route('documents.show', ['document' => $pdf, 'preview' => 1]);
        $this->get('/admin/clients/'.$client->id.'/edit')->assertOk()
            ->assertSee('data-selected-preview', false)->assertSee($previewUrl, false);
        $this->get($previewUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename=document.pdf')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('documents.show', $pdf))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=aadhaar.pdf');

        $this->post('/admin/clients', array_replace($this->data(), ['code' => 'OTHER100', 'email' => 'other@example.test']))
            ->assertSessionHasNoErrors();
        $other = Client::where('code', 'OTHER100')->sole();
        $this->flushSession();
        $this->actingAs($client->user);
        $this->get('/profile')->assertOk()->assertSee($previewUrl, false);
        $this->get($previewUrl)->assertOk();
        $this->flushSession();
        $this->actingAs($other->user);
        $this->get($previewUrl)->assertForbidden();
    }

    public function test_single_page_form_validates_and_saves_multipart_json_requests(): void
    {
        $this->get('/admin/clients/create')->assertOk()->assertSee('data-client-form', false)
            ->assertSee('Personal details')->assertSee('Bank details')->assertSee('Nominee details')
            ->assertSee('Documents')->assertDontSee('data-tab-panel')->assertDontSee('role="tablist"', false);
        $headers = ['Accept' => 'application/json'];
        $this->post('/admin/clients', array_replace($this->data(), ['ifsc' => 'INVALID']), $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('ifsc');
        $this->assertDatabaseCount('clients', 0);
        $response = $this->post('/admin/clients', $this->data(), $headers)->assertOk();
        $client = Client::sole();
        $response->assertJsonPath('redirect', route('clients.show', $client));
        $this->assertSame(3, $client->documents()->count());
        $this->post('/admin/clients/'.$client->id, array_replace($this->data(), [
            '_method' => 'PUT', 'first_name' => 'Updated',
        ]), $headers)->assertOk()->assertJsonPath('redirect', route('clients.show', $client));
        $this->assertSame('Updated Test Shah', $client->user->fresh()->name);
    }

    private function failSecondWrite(): FilesystemAdapter
    {
        $disk = Storage::disk('local');
        $writes = 0;
        $failing = Mockery::mock(FilesystemAdapter::class);
        $failing->shouldReceive('putFileAs')->andReturnUsing(function (...$args) use ($disk, &$writes) {
            if (++$writes === 2) {
                throw UnableToWriteFile::atLocation('documents/test');
            }

            return $disk->putFileAs(...$args);
        });
        $failing->shouldReceive('delete')->andReturnUsing(fn (...$args) => $disk->delete(...$args));
        Storage::shouldReceive('disk')->with('local')->andReturn($failing);

        return $disk;
    }

    public function test_second_upload_failure_creates_no_partial_client_and_retry_succeeds(): void
    {
        $disk = $this->failSecondWrite();
        $this->from('/admin/clients/create')->post('/admin/clients', $this->data())
            ->assertRedirect('/admin/clients/create')->assertSessionHasErrors('upload')
            ->assertSessionHasInput('bank_name', 'Test Bank');
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame([], $disk->allFiles('documents'));
        $this->post('/admin/clients', $this->data())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('documents', 3);
    }

    public function test_failed_edit_preserves_client_details_and_all_previous_documents(): void
    {
        $this->post('/admin/clients', $this->data())->assertSessionHasNoErrors();
        $client = Client::sole();
        $original = $client->documents()->pluck('path', 'id')->all();
        $disk = $this->failSecondWrite();
        $this->put('/admin/clients/'.$client->id, array_replace($this->data(), ['first_name' => 'Changed']))
            ->assertSessionHasErrors('upload');
        $this->assertSame('Mira Test Shah', $client->user->fresh()->name);
        $this->assertSame($original, $client->documents()->pluck('path', 'id')->all());
        $this->assertCount(3, $disk->allFiles('documents'));
        foreach ($original as $path) {
            $disk->assertExists($path);
        }
    }

    public function test_database_failure_after_client_creation_rolls_back_and_removes_staged_files(): void
    {
        Document::creating(function ($document) {
            if ($document->kind === 'aadhaar') {
                throw ValidationException::withMessages(['upload' => 'Simulated document record failure']);
            }
        });
        try {
            $this->post('/admin/clients', $this->data())->assertSessionHasErrors('upload');
        } finally {
            Event::forget('eloquent.creating: '.Document::class);
        }
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));
    }

    public function test_validation_failure_retains_text_and_exposes_bank_tab_error_without_creating_client(): void
    {
        $this->from('/admin/clients/create')->post('/admin/clients', array_replace($this->data(), ['ifsc' => 'INVALID']))
            ->assertSessionHasErrors('ifsc')->assertSessionHasInput('account_number', '001234567890');
        $this->get('/admin/clients/create')->assertOk()->assertSee('data-validation-error', false)
            ->assertSee('reselect any documents')->assertSee('001234567890');
        $this->assertDatabaseCount('clients', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));
    }
}
