<?php

namespace Tests\Feature\Members;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->seed(CategorySeeder::class);
    }

    public function test_index_lists_the_four_default_categories_with_member_counts(): void
    {
        $student = MembershipCategory::where('name', 'Student')->sole();
        Member::factory()->count(2)->for($student, 'category')->create();

        $response = $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk();

        foreach (['Student', 'Regular', 'Senior Citizen', 'Corporate'] as $name) {
            $response->assertSee($name);
        }
        $this->assertSame(2, $response->viewData('categories')->firstWhere('name', 'Student')->members_count);
    }

    public function test_admin_can_create_a_category(): void
    {
        $this->actingAs($this->admin)->get(route('admin.categories.create'))->assertOk()->assertSee('New category');

        $this->actingAs($this->admin)
            ->post(route('admin.categories.store'), ['name' => '  Family   Plan ', 'description' => 'Households', 'is_active' => '1'])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('membership_categories', ['name' => 'Family Plan', 'description' => 'Households', 'is_active' => true]);
    }

    public function test_category_validation(): void
    {
        $this->actingAs($this->admin)->post(route('admin.categories.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post(route('admin.categories.store'), ['name' => str_repeat('x', 51)])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post(route('admin.categories.store'), ['name' => 'student'])
            ->assertSessionHasErrors(['name' => 'A category with this name already exists.']);

        $this->assertSame(4, MembershipCategory::count());
    }

    public function test_admin_can_rename_and_deactivate_a_category(): void
    {
        $regular = MembershipCategory::where('name', 'Regular')->sole();

        // Keeping its own name is not a duplicate.
        $this->actingAs($this->admin)->get(route('admin.categories.edit', $regular))->assertOk();
        $this->actingAs($this->admin)
            ->put(route('admin.categories.update', $regular), ['name' => 'Regular', 'description' => 'Updated', 'is_active' => '0'])
            ->assertSessionHasNoErrors();

        $regular->refresh();
        $this->assertFalse($regular->is_active);
        $this->assertSame('Updated', $regular->description);

        $this->actingAs($this->admin)->put(route('admin.categories.update', $regular), ['name' => 'Corporate', 'is_active' => '1'])
            ->assertSessionHasErrors('name');
    }

    public function test_deactivated_category_is_not_offered_for_new_members(): void
    {
        MembershipCategory::where('name', 'Corporate')->update(['is_active' => false]);

        $this->actingAs($this->admin)->get(route('admin.members.create'))
            ->assertSee('Student')
            ->assertDontSee('Corporate');
    }

    public function test_unused_category_can_be_deleted(): void
    {
        $unused = MembershipCategory::factory()->create();

        $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $unused))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($unused);
    }

    public function test_category_in_use_cannot_be_deleted_even_by_archived_members(): void
    {
        $student = MembershipCategory::where('name', 'Student')->sole();
        Member::factory()->for($student, 'category')->create();
        $senior = MembershipCategory::where('name', 'Senior Citizen')->sole();
        Member::factory()->for($senior, 'category')->create()->delete(); // soft-deleted member still references it

        foreach ([$student, $senior] as $category) {
            $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $category))
                ->assertSessionHas('error');
            $this->assertModelExists($category);
        }
    }

    public function test_staff_cannot_manage_categories(): void
    {
        $staff = User::factory()->member()->create();
        $category = MembershipCategory::first();
        $before = MembershipCategory::count();   // the member account brings its own member record (and category)

        $this->actingAs($staff)->get(route('admin.categories.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.categories.create'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.categories.store'), ['name' => 'Hack'])->assertForbidden();
        $this->actingAs($staff)->put(route('admin.categories.update', $category), ['name' => 'Hack'])->assertForbidden();
        $this->actingAs($staff)->delete(route('admin.categories.destroy', $category))->assertForbidden();

        $this->assertDatabaseMissing('membership_categories', ['name' => 'Hack']);
        $this->assertSame($before, MembershipCategory::count());
    }
}
