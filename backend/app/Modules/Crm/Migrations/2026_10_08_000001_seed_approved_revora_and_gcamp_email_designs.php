<?php

declare(strict_types=1);

use App\Modules\Crm\Services\Outreach\EmailTemplateDesignService;
use App\Modules\Crm\Services\Outreach\EmailTemplateOptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two approved reusable email designs, Revora Quotation and GCamp Proposal, each with its active designed template
 * ("<name> · Warm & professional") and the custom purpose the template is filed under, in the provider org.
 *
 * Data, not schema, so it must be safe on any database that already has some, all or none of it:
 *  - every record is matched by what it is (org + design name; org + purpose + tone; org + option type + normalized
 *    value), never by an id, so it does not depend on the ids an install happened to hand out;
 *  - a missing record is created, a live one is updated to the approved version, and nothing is ever duplicated;
 *  - a soft-deleted custom purpose is restored, as re-adding one in Settings does (its unique index covers deleted
 *    rows); a soft-deleted design or template is left deleted and a new live one is created beside it;
 *  - nothing else in the org is touched, and running it again changes nothing.
 *
 * The provider is the FIRST org by id, the rule CurrentOrg::id() and the provider-entitlement migration use. No org
 * means a fresh install with nothing to give these to, so it does nothing.
 *
 * Both documents are checked by the same validators the Settings API runs, and the template's `body` is derived from
 * them exactly as Settings derives it, so these rows are indistinguishable from ones saved through the UI. A document
 * that fails validation stops the migration before anything is written.
 */
return new class extends Migration
{
    private const TONE = 'Warm & professional';

    public function up(): void
    {
        if (! Schema::hasTable('orgs') || ! Schema::hasColumn('crm_email_templates', 'content')) {
            return;
        }

        $orgId = DB::table('orgs')->orderBy('id')->value('id');
        if ($orgId === null) {
            return;
        }

        $designs = app(EmailTemplateDesignService::class);
        $records = json_decode(self::APPROVED_JSON, true, 512, JSON_THROW_ON_ERROR);

        foreach ($records as $record) {
            $layoutJson = json_encode($record['layout'], JSON_THROW_ON_ERROR);
            $contentJson = json_encode($record['content'], JSON_THROW_ON_ERROR);
            $problem = $designs->layoutProblem($layoutJson) ?? $designs->contentProblem($contentJson, $record['layout']);
            if ($problem !== null) {
                throw new RuntimeException("Approved email design \"{$record['name']}\" is invalid: ".json_encode($problem));
            }
        }

        DB::transaction(function () use ($orgId, $records, $designs): void {
            $now = now();

            foreach ($records as $record) {
                $layoutJson = json_encode($record['layout'], JSON_THROW_ON_ERROR);
                $contentJson = json_encode($record['content'], JSON_THROW_ON_ERROR);

                $this->purpose((int) $orgId, $record['purpose'], $now);

                $designId = DB::table('crm_email_designs')
                    ->where('org_id', $orgId)->where('name', $record['name'])->whereNull('deleted_at')
                    ->orderBy('id')->value('id');
                if ($designId === null) {
                    $designId = DB::table('crm_email_designs')->insertGetId([
                        'org_id' => $orgId, 'name' => $record['name'], 'is_default' => false, 'layout' => $layoutJson,
                        'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('crm_email_designs')->where('id', $designId)->update(['layout' => $layoutJson, 'updated_at' => $now]);
                }

                $template = [
                    'subject' => $record['subject'],
                    'body' => $designs->plainTextBody($record['layout'], $record['content']),
                    'content' => $contentJson,
                    'email_design_id' => $designId,
                    'is_active' => true,
                    'updated_at' => $now,
                ];
                $templateId = DB::table('crm_email_templates')
                    ->where('org_id', $orgId)->where('purpose', $record['purpose'])->where('tone', self::TONE)->whereNull('deleted_at')
                    ->orderBy('id')->value('id');
                if ($templateId === null) {
                    DB::table('crm_email_templates')->insert([
                        ...$template, 'org_id' => $orgId, 'purpose' => $record['purpose'], 'tone' => self::TONE,
                        'created_by' => null, 'created_at' => $now,
                    ]);
                } else {
                    DB::table('crm_email_templates')->where('id', $templateId)->update($template);
                }
            }
        });
    }

    /** The custom purpose the template is filed under: created, restored if deleted, or left as it is. */
    private function purpose(int $orgId, string $value, mixed $now): void
    {
        $normalized = EmailTemplateOptionService::normalize($value);
        $existing = DB::table('crm_email_template_options')
            ->where('org_id', $orgId)->where('type', 'purpose')->where('normalized_value', $normalized)->first();

        if ($existing === null) {
            DB::table('crm_email_template_options')->insert([
                'org_id' => $orgId, 'type' => 'purpose', 'value' => $value, 'normalized_value' => $normalized,
                'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ($existing->deleted_at !== null) {
            DB::table('crm_email_template_options')->where('id', $existing->id)->update(['deleted_at' => null, 'updated_at' => $now]);
        }
    }

    /**
     * Not reversed. These rows are ordinary Settings records the moment they exist: people edit them, and emails
     * and drafts reference them, so deleting them on rollback could take work with it.
     */
    public function down(): void
    {
        //
    }

    private const APPROVED_JSON = <<<'APPROVED_JSON'
[
  {
    "name": "Revora Quotation",
    "purpose": "Revora Quotation",
    "tone": "Warm & professional",
    "subject": "Website proposal and quotation for Revora",
    "layout": {
      "version": 1,
      "styles": {
        "font": "helvetica",
        "background_color": "#F5F5F7",
        "content_background_color": "#FFFFFF",
        "text_color": "#1D1D1F",
        "muted_text_color": "#6E6E73",
        "link_color": "#006F8A",
        "accent_color": "#006F8A",
        "accent_text_color": "#FFFFFF",
        "divider_color": "#D2D2D7",
        "card_background_color": "#F5F5F7",
        "eyebrow_color": "#6E6E73",
        "secondary_color": "#1D1D1F",
        "secondary_text_color": "#FFFFFF",
        "container_radius": 18,
        "content_padding": 48,
        "card_radius": 18,
        "card_padding": 28,
        "link_underline": false,
        "card_eyebrow_size": 13,
        "card_title_size": 22,
        "row_title_size": 16,
        "row_description_size": 15,
        "row_padding": 14,
        "signature_size": 17,
        "signature_detail_size": 15,
        "button_padding_y": 14,
        "button_padding_x": 28
      },
      "header": [
        {
          "type": "spacer",
          "id": "header-top",
          "height": 12
        },
        {
          "type": "heading",
          "id": "brand",
          "level": 3,
          "text": "Vite SEO",
          "align": "center",
          "font_size": 20
        }
      ],
      "signature": {
        "enabled": true,
        "sign_off": "Best regards,",
        "align": "center",
        "show_company": true,
        "show_email": true,
        "show_phone": true
      },
      "footer": [
        {
          "type": "spacer",
          "id": "footer-gap",
          "height": 24
        },
        {
          "type": "divider",
          "id": "footer-rule",
          "thickness": 1
        },
        {
          "type": "spacer",
          "id": "footer-rule-gap",
          "height": 8
        },
        {
          "type": "footer",
          "id": "footer-text",
          "align": "center",
          "font_size": 12,
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Vite SEO Digital Advertising OPC"
                  },
                  {
                    "type": "hardBreak"
                  },
                  {
                    "type": "text",
                    "text": "Unit 26L, Burgundy Corporate Tower, 252 Sen. Gil J. Puyat Ave, Pio del Pilar, Makati City, 1230 Metro Manila"
                  },
                  {
                    "type": "hardBreak"
                  },
                  {
                    "type": "text",
                    "text": "viteseo.ph",
                    "marks": [
                      {
                        "type": "link",
                        "attrs": {
                          "href": "https://viteseo.ph"
                        }
                      }
                    ]
                  },
                  {
                    "type": "text",
                    "text": " · "
                  },
                  {
                    "type": "text",
                    "text": "Portfolio",
                    "marks": [
                      {
                        "type": "link",
                        "attrs": {
                          "href": "https://viteseo.ph/portfolio"
                        }
                      }
                    ]
                  }
                ]
              }
            ]
          }
        },
        {
          "type": "spacer",
          "id": "footer-bottom",
          "height": 16
        }
      ]
    },
    "content": {
      "version": 1,
      "preheader": "A 5-page website for ₱35,000, with a free SEO offer until Sunday, 11 October.",
      "blocks": [
        {
          "type": "spacer",
          "id": "before-title",
          "height": 32
        },
        {
          "type": "heading",
          "id": "title",
          "level": 1,
          "text": "Your website quotation.",
          "align": "center",
          "font_size": 36
        },
        {
          "type": "spacer",
          "id": "before-greeting",
          "height": 8
        },
        {
          "type": "text",
          "id": "greeting",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Hi Sarah,"
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "text",
          "id": "intro-1",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Good day. This is Uriel from Vite SEO Digital Advertising OPC. Thank you for answering our fit check form, and thank you to Megan for reaching out on Messenger."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "text",
          "id": "intro-2",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "We prepared a proposal for Revora's website from what you told us: an informational site with an inquiry form that sends each inquiry to your email. A summary of the quotation is below, and the button opens the full details."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "spacer",
          "id": "before-button",
          "height": 16
        },
        {
          "type": "button",
          "id": "view-quotation",
          "label": "View the full quotation",
          "url": "https://viteseo.ph/quotation",
          "align": "center",
          "radius": 24
        },
        {
          "type": "spacer",
          "id": "before-card",
          "height": 32
        },
        {
          "type": "card",
          "id": "quotation-summary",
          "align": "center",
          "eyebrow": "Quotation summary",
          "title": "Basic website. ₱35,000, one-time.",
          "blocks": [
            {
              "type": "divider",
              "id": "summary-rule",
              "thickness": 1
            },
            {
              "type": "rows",
              "id": "quotation-lines",
              "items": [
                {
                  "title": "Website",
                  "description": "5 pages, with a contact form that sends each inquiry to your email."
                },
                {
                  "title": "Hosting and domain",
                  "description": "Included for the first year. We send .com domain options and you choose one."
                },
                {
                  "title": "Free SEO offer",
                  "description": "5 SEO-optimized blog posts, a sitemap, on-page and off-page SEO, 2 to 3 backlinks, local SEO, and Google Business Profile setup and optimization. No extra charge when the agreement is signed by Sunday, 11 October 2026."
                },
                {
                  "title": "Payment",
                  "description": "₱17,500 on signing and ₱17,500 on delivery."
                },
                {
                  "title": "Delivery time",
                  "description": "2 to 6 weeks, depending on how quickly your content and approvals reach us."
                },
                {
                  "title": "Monthly search retainer, optional",
                  "description": "₱25,000 a month. You can take the website on its own."
                }
              ],
              "align": "center",
              "dividers": true
            },
            {
              "type": "text",
              "id": "fine-print",
              "content": {
                "type": "doc",
                "content": [
                  {
                    "type": "paragraph",
                    "content": [
                      {
                        "type": "text",
                        "text": "Prices in Philippine pesos, VAT not included. This quotation is valid for 30 days."
                      }
                    ]
                  }
                ]
              },
              "align": "center",
              "font_size": 13,
              "color": "#6E6E73"
            }
          ]
        },
        {
          "type": "spacer",
          "id": "after-card",
          "height": 24
        },
        {
          "type": "text",
          "id": "next-step",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "The next step is a free 30-minute discovery meeting on Google Meet. We will confirm your page list there, and you can send us the layouts and content you already have. Would any day this week work for you?"
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "text",
          "id": "thanks",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Thank you for your time, Sarah. Looking forward to your reply."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "spacer",
          "id": "before-actions",
          "height": 12
        },
        {
          "type": "actions",
          "id": "closing-actions",
          "items": [
            {
              "kind": "button",
              "label": "See our portfolio",
              "url": "https://viteseo.ph/portfolio",
              "variant": "secondary"
            },
            {
              "kind": "link",
              "label": "Contact us ›",
              "url": "mailto:business@viteseo.ph"
            }
          ],
          "align": "center",
          "radius": 24
        },
        {
          "type": "spacer",
          "id": "before-signature",
          "height": 20
        }
      ]
    }
  },
  {
    "name": "GCamp Proposal",
    "purpose": "GCamp Proposal",
    "tone": "Warm & professional",
    "subject": "GCamp streaming app proposal and NFC card quote",
    "layout": {
      "version": 1,
      "styles": {
        "font": "helvetica",
        "background_color": "#F5F5F7",
        "content_background_color": "#FFFFFF",
        "text_color": "#1D1D1F",
        "muted_text_color": "#6E6E73",
        "link_color": "#006F8A",
        "accent_color": "#006F8A",
        "accent_text_color": "#FFFFFF",
        "divider_color": "#D2D2D7",
        "card_background_color": "#F5F5F7",
        "eyebrow_color": "#6E6E73",
        "secondary_color": "#1D1D1F",
        "secondary_text_color": "#FFFFFF",
        "container_radius": 18,
        "content_padding": 48,
        "card_radius": 18,
        "card_padding": 28,
        "link_underline": false,
        "card_eyebrow_size": 13,
        "card_title_size": 22,
        "row_title_size": 16,
        "row_description_size": 15,
        "row_padding": 14,
        "signature_size": 17,
        "signature_detail_size": 15,
        "button_padding_y": 14,
        "button_padding_x": 28
      },
      "header": [
        {
          "type": "spacer",
          "id": "header-top",
          "height": 12
        },
        {
          "type": "heading",
          "id": "brand",
          "level": 3,
          "text": "Vite SEO",
          "align": "center",
          "font_size": 20
        }
      ],
      "signature": {
        "enabled": true,
        "sign_off": "Best regards,",
        "align": "center",
        "show_company": true,
        "show_email": true,
        "show_phone": true
      },
      "footer": [
        {
          "type": "spacer",
          "id": "footer-gap",
          "height": 24
        },
        {
          "type": "divider",
          "id": "footer-rule",
          "thickness": 1
        },
        {
          "type": "spacer",
          "id": "footer-rule-gap",
          "height": 8
        },
        {
          "type": "footer",
          "id": "footer-text",
          "align": "center",
          "font_size": 12,
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Vite SEO Digital Advertising OPC"
                  },
                  {
                    "type": "hardBreak"
                  },
                  {
                    "type": "text",
                    "text": "Unit 26L, Burgundy Corporate Tower, 252 Sen. Gil J. Puyat Ave, Pio del Pilar, Makati City, 1230 Metro Manila"
                  },
                  {
                    "type": "hardBreak"
                  },
                  {
                    "type": "text",
                    "text": "viteseo.ph",
                    "marks": [
                      {
                        "type": "link",
                        "attrs": {
                          "href": "https://viteseo.ph"
                        }
                      }
                    ]
                  },
                  {
                    "type": "text",
                    "text": " · "
                  },
                  {
                    "type": "text",
                    "text": "Portfolio",
                    "marks": [
                      {
                        "type": "link",
                        "attrs": {
                          "href": "https://viteseo.ph/portfolio"
                        }
                      }
                    ]
                  }
                ]
              }
            ]
          }
        },
        {
          "type": "spacer",
          "id": "footer-bottom",
          "height": 16
        }
      ]
    },
    "content": {
      "version": 1,
      "preheader": "The streaming app proposal, a separate quote for NFC cards, and what we need by October 7.",
      "blocks": [
        {
          "type": "spacer",
          "id": "before-title",
          "height": 32
        },
        {
          "type": "heading",
          "id": "title",
          "level": 1,
          "text": "Your proposal is ready.",
          "align": "center",
          "font_size": 36
        },
        {
          "type": "spacer",
          "id": "before-greeting",
          "height": 8
        },
        {
          "type": "text",
          "id": "greeting",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Hi MJ,"
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "text",
          "id": "intro-1",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Good day. This is Uriel from Vite SEO Digital Advertising OPC. Thank you for the time you and West gave us at the consultation."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "text",
          "id": "intro-2",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Our proposal for GCamp's streaming app is ready, built to launch on Google Play and the App Store on October 30. We have also prepared a separate quote for NFC cards."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "spacer",
          "id": "before-button",
          "height": 16
        },
        {
          "type": "button",
          "id": "view-proposal",
          "label": "See the app proposal",
          "url": "https://viteseo.ph/proposal",
          "align": "center",
          "radius": 24
        },
        {
          "type": "text",
          "id": "nfc-quote-link",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "See the NFC card quote ›",
                    "marks": [
                      {
                        "type": "link",
                        "attrs": {
                          "href": "https://viteseo.ph/nfc-quote"
                        }
                      }
                    ]
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 16
        },
        {
          "type": "spacer",
          "id": "before-card-1",
          "height": 32
        },
        {
          "type": "card",
          "id": "fees",
          "align": "center",
          "eyebrow": "Our fees, for our work",
          "title": "Three options.",
          "blocks": [
            {
              "type": "divider",
              "id": "fees-rule",
              "thickness": 1
            },
            {
              "type": "rows",
              "id": "fee-options",
              "items": [
                {
                  "title": "Essential · ₱50,000",
                  "description": "The app, the video library and the player."
                },
                {
                  "title": "Standard · ₱65,000",
                  "description": "Adds viewer sign-in and a content dashboard for your team."
                },
                {
                  "title": "Complete · ₱80,000 · Recommended",
                  "description": "Adds live streaming and viewing analytics."
                },
                {
                  "title": "Monthly retainer · ₱25,000",
                  "description": "Continuous improvement, fixes, content updates and monitoring."
                }
              ],
              "align": "center",
              "dividers": true
            }
          ]
        },
        {
          "type": "spacer",
          "id": "after-card-1",
          "height": 8
        },
        {
          "type": "text",
          "id": "fees-note",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Each option is a one-time build fee, and the full cost breakdown is inside the proposal."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "spacer",
          "id": "before-card-2",
          "height": 24
        },
        {
          "type": "card",
          "id": "external-costs",
          "align": "center",
          "eyebrow": "Paid to other companies, billed to GCamp",
          "title": "Costs outside this quotation.",
          "blocks": [
            {
              "type": "divider",
              "id": "external-rule",
              "thickness": 1
            },
            {
              "type": "rows",
              "id": "external-lines",
              "items": [
                {
                  "title": "Apple Developer Program",
                  "description": "US$99 a year."
                },
                {
                  "title": "Google Play Console",
                  "description": "US$25, one time."
                },
                {
                  "title": "Cloudflare video streaming",
                  "description": "Billed by usage each month."
                },
                {
                  "title": "Your approval first",
                  "description": "Once development starts, any other added cost comes to you first. Nothing extra is billed without your approval, and we always bring free and paid options with their pros and cons."
                }
              ],
              "align": "center",
              "dividers": true
            }
          ]
        },
        {
          "type": "spacer",
          "id": "before-card-3",
          "height": 24
        },
        {
          "type": "card",
          "id": "nfc-cards",
          "align": "center",
          "eyebrow": "A separate quote from the app",
          "title": "NFC cards.",
          "blocks": [
            {
              "type": "divider",
              "id": "nfc-rule",
              "thickness": 1
            },
            {
              "type": "rows",
              "id": "nfc-lines",
              "items": [
                {
                  "title": "₱999 per card",
                  "description": "Tap a card on a phone and it opens the link you choose."
                },
                {
                  "title": "Minimum order of 20 cards",
                  "description": "₱19,980 for the minimum order."
                }
              ],
              "align": "center",
              "dividers": true
            },
            {
              "type": "divider",
              "id": "nfc-link-rule",
              "thickness": 1
            },
            {
              "type": "text",
              "id": "nfc-card-link",
              "content": {
                "type": "doc",
                "content": [
                  {
                    "type": "paragraph",
                    "content": [
                      {
                        "type": "text",
                        "text": "See the NFC card quote ›",
                        "marks": [
                          {
                            "type": "link",
                            "attrs": {
                              "href": "https://viteseo.ph/nfc-quote"
                            }
                          }
                        ]
                      }
                    ]
                  }
                ]
              },
              "align": "center",
              "font_size": 16
            }
          ]
        },
        {
          "type": "spacer",
          "id": "after-card-3",
          "height": 24
        },
        {
          "type": "text",
          "id": "closing-1",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "To keep the October 30 date, we need two things this week: your choice of option by Wednesday, October 7, and GCamp's Apple and Google developer accounts started now. Their verification can take up to seven business days."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "text",
          "id": "closing-2",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "I have copied West and Nicole so everyone has the same proposal. Would a short call this week work for you, so we can walk your team through it?"
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "text",
          "id": "thanks",
          "content": {
            "type": "doc",
            "content": [
              {
                "type": "paragraph",
                "content": [
                  {
                    "type": "text",
                    "text": "Thank you for your time, MJ. Looking forward to your thoughts."
                  }
                ]
              }
            ]
          },
          "align": "center",
          "font_size": 17
        },
        {
          "type": "spacer",
          "id": "before-actions",
          "height": 12
        },
        {
          "type": "actions",
          "id": "closing-actions",
          "items": [
            {
              "kind": "button",
              "label": "Contact us",
              "url": "mailto:business@viteseo.ph",
              "variant": "secondary"
            },
            {
              "kind": "link",
              "label": "See our portfolio ›",
              "url": "https://viteseo.ph/portfolio"
            }
          ],
          "align": "center",
          "radius": 24
        },
        {
          "type": "spacer",
          "id": "before-signature",
          "height": 20
        }
      ]
    }
  }
]
APPROVED_JSON;
};
