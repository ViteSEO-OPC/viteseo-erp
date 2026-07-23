<?php

namespace App\Modules\Crm\Database\Factories;

use App\Modules\Crm\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Set explicitly: the factory lives under App\Modules\Crm\, so Laravel's
     * convention-based resolver cannot infer the model and guesses App\Lead.
     */
    protected $model = Lead::class;

    protected static ?array $businesses = [
        'Casa Verde Bistro', 'Ironline Auto Care', 'Serenity Spa Makati',
        'Molave Dental Clinic', 'Bluewave Travel & Tours', 'Golden Pacific Realty',
        'Silver Oak Consultancy', 'Bright Future Academy', 'Prime Properties',
        'Elite Medical Center', 'Capital Legal Partners', 'Metro Retail Group',
        'North Star Logistics', 'Heritage Designs Studio', 'Summit Technologies',
        'Crystal Clear Solutions', 'Pacesetter Marketing', 'Dynamic Ventures',
        'Innovate IT Systems', 'NextGen Networks', 'ProActive Resources',
        'Strategic Concepts Inc', 'Premier Healthcare', 'Central Hub Logistics',
    ];

    protected static ?array $niches = [
        'Food & Beverage', 'Automotive', 'Wellness', 'Healthcare',
        'Travel & Tourism', 'Real Estate', 'Consulting', 'Education',
        'Technology', 'Legal', 'Retail', 'Logistics', 'Design',
    ];

    protected static ?array $statuses = [
        'interested', 'interested', 'awaiting-reply', 'awaiting-reply',
        'declined', 'call-back-req', 'call-back-req',
    ];

    public function definition(): array
    {
        $status = fake()->randomElement(static::$statuses);

        return [
            'business_name' => fake()->randomElement(static::$businesses).' '.fake()->companySuffix(),
            'contact_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'industry' => fake()->randomElement(static::$niches),
            'website_status' => fake()->randomElement(['has-website', 'no-website', 'needs-redesign']),
            'priority' => fake()->randomElement(['high', 'high', 'high', 'medium', 'medium', 'medium', 'medium', 'low', 'low']),
            'email_outreach' => fake()->randomElement(['sent', 'opened', 'replied', 'none']),
            'cold_calling' => fake()->randomElement(['called', 'scheduled', 'not-called']),
            'status' => $status,
            'last_activity' => fake()->dateTimeBetween('-30 days', 'now'),
            'score' => fake()->numberBetween(20, 100),
            // Unassigned by default — leads are distributed to BDs intentionally (Admin action /
            // the assignedTo() state in tests). "Unassigned" is a transitional state, not the
            // production workflow (Phase 1 requires an assigned BD on create/import).
            'assigned_to' => null,
            'created_by' => User::inRandomOrder()->first()?->id ?? 1,
        ];
    }

    /**
     * Assign the lead to a specific Business Development Manager. Use in tests that
     * need an owned lead; the default factory output is intentionally unassigned.
     */
    public function assignedTo(User $bd): static
    {
        return $this->state(fn (): array => ['assigned_to' => $bd->id]);
    }
}
