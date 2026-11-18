@php
    $kind = $content['form'] ?? 'contact';
    $segment = str_replace('_', '-', $kind);
    $heading = $content['heading'] ?? null;
    $body = $content['body'] ?? null;
    $items = $content['items'] ?? [];
    $submitLabel = $content['submit_label'] ?? 'Send request';
    $headingId = $heading ? \Illuminate\Support\Str::slug($heading) : 'enquiry-form';
    $needsBusiness = in_array($kind, ['quote', 'pos_demo', 'partnership'], true);
    $contactDetails = $contact ?? ['email' => null, 'phone' => null, 'whatsapp_url' => null, 'whatsapp' => null];
    $hasContact = $contactDetails['email'] || $contactDetails['phone'] || $contactDetails['whatsapp_url'];
    $token = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['ts' => time(), 'type' => $kind]));
@endphp
<section class="section section--tint" aria-labelledby="{{ $headingId }}">
    <div class="container contact-grid">
        <div>
            @if ($heading)
                <h2 id="{{ $headingId }}">{{ $heading }}</h2>
            @endif
            @if ($body)
                <p class="lead">{{ $body }}</p>
            @endif
            @if (! empty($items))
                <ul class="check-list">
                    @foreach ($items as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($hasContact)
                <ul class="contact-list form-contact-list">
                    @if ($contactDetails['email'])
                        <li><span>Email</span><a href="mailto:{{ $contactDetails['email'] }}">{{ $contactDetails['email'] }}</a></li>
                    @endif
                    @if ($contactDetails['phone'])
                        <li><span>Phone</span><a href="tel:{{ preg_replace('/\s+/', '', $contactDetails['phone']) }}">{{ $contactDetails['phone'] }}</a></li>
                    @endif
                    @if ($contactDetails['whatsapp_url'])
                        <li><span>WhatsApp</span><a href="{{ $contactDetails['whatsapp_url'] }}" rel="noopener" target="_blank">{{ $contactDetails['whatsapp'] }}</a></li>
                    @endif
                </ul>
            @endif
        </div>
        <div class="contact-card">
            @if (session('lead_success'))
                <div class="form-alert form-alert--success" role="status" data-testid="lead-success">
                    {{ session('lead_success') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="form-alert form-alert--error" role="alert" data-testid="lead-errors">
                    <p>Please check the highlighted fields and try again.</p>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="POST" action="{{ route('forms.submit', ['form' => $segment]) }}" class="public-form" data-form="{{ $kind }}">
                @csrf
                <input type="hidden" name="form_token" value="{{ $token }}">
                <input type="hidden" name="landing_page" value="{{ url()->current() }}">
                @foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $utm)
                    @if (request()->query($utm))
                        <input type="hidden" name="{{ $utm }}" value="{{ request()->query($utm) }}">
                    @endif
                @endforeach
                <div class="hp-field" aria-hidden="true">
                    <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="{{ $kind }}-contact_name">Your name</label>
                        <input id="{{ $kind }}-contact_name" name="contact_name" type="text" maxlength="160" autocomplete="name" required value="{{ old('contact_name') }}">
                        @error('contact_name')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-field">
                        <label for="{{ $kind }}-business_name">Business name{{ $needsBusiness ? '' : ' (optional)' }}</label>
                        <input id="{{ $kind }}-business_name" name="business_name" type="text" maxlength="190" autocomplete="organization" value="{{ old('business_name') }}" @if ($needsBusiness) required @endif>
                        @error('business_name')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-field">
                        <label for="{{ $kind }}-email">Email</label>
                        <input id="{{ $kind }}-email" name="email" type="email" maxlength="190" autocomplete="email" value="{{ old('email') }}" @if ($kind !== 'callback') required @endif>
                        @error('email')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-field">
                        <label for="{{ $kind }}-phone">Phone{{ $kind === 'callback' ? '' : ' (optional)' }}</label>
                        <input id="{{ $kind }}-phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="{{ old('phone') }}" @if ($kind === 'callback') required @endif>
                        @error('phone')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    @if (in_array($kind, ['quote', 'pos_demo'], true))
                        <div class="form-field">
                            <label for="{{ $kind }}-branches_count">Branches or stock locations (optional)</label>
                            <input id="{{ $kind }}-branches_count" name="branches_count" type="number" min="1" step="1" inputmode="numeric" value="{{ old('branches_count') }}">
                            @error('branches_count')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @if ($kind === 'quote')
                        <div class="form-field">
                            <label for="quote-catalogue_size">Approximate catalogue size (optional)</label>
                            <input id="quote-catalogue_size" name="catalogue_size" type="number" min="1" step="1" inputmode="numeric" value="{{ old('catalogue_size') }}">
                            @error('catalogue_size')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-field">
                            <label for="quote-service_requested">What do you need? (optional)</label>
                            <select id="quote-service_requested" name="service_requested">
                                <option value="">Select an option</option>
                                @foreach (['Scheduled stock-taking', 'One-off stock count', 'Independent verification', 'Recount support', 'Not sure yet'] as $option)
                                    <option value="{{ $option }}" @selected(old('service_requested') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('service_requested')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-field">
                            <label for="quote-stocktake_frequency">Preferred frequency (optional)</label>
                            <select id="quote-stocktake_frequency" name="stocktake_frequency">
                                <option value="">Select an option</option>
                                @foreach (['Weekly', 'Monthly', 'Quarterly', 'Annually', 'One-off', 'Not sure yet'] as $option)
                                    <option value="{{ $option }}" @selected(old('stocktake_frequency') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('stocktake_frequency')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-field">
                            <label for="quote-town">Town or city (optional)</label>
                            <input id="quote-town" name="town" type="text" maxlength="120" autocomplete="address-level2" value="{{ old('town') }}">
                            @error('town')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @if ($kind === 'pos_demo')
                        <div class="form-field">
                            <label for="pos-demo-industry">Business type (optional)</label>
                            <select id="pos-demo-industry" name="industry">
                                <option value="">Select an option</option>
                                @foreach (['Supermarket / grocery', 'Pharmacy', 'Hardware', 'Wholesale', 'Restaurant / food service', 'Other'] as $option)
                                    <option value="{{ $option }}" @selected(old('industry') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('industry')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    <div class="form-field form-field--wide">
                        <label for="{{ $kind }}-message">{{ $kind === 'callback' ? 'When should we call? (optional)' : 'Message' }}{{ in_array($kind, ['contact', 'partnership'], true) ? '' : ' (optional)' }}</label>
                        <textarea id="{{ $kind }}-message" name="message" rows="4" maxlength="5000" @if (in_array($kind, ['contact', 'partnership'], true)) required @endif>{{ old('message') }}</textarea>
                        @error('message')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="form-consent">
                    <label class="form-check">
                        <input type="checkbox" name="consent_transactional" value="1" required @checked(old('consent_transactional'))>
                        <span>I agree that BRATHAMATU may contact me about this request.</span>
                    </label>
                    @error('consent_transactional')<p class="field-error">{{ $message }}</p>@enderror
                    <label class="form-check">
                        <input type="checkbox" name="consent_marketing" value="1" @checked(old('consent_marketing'))>
                        <span>Send me occasional stock-taking and BRATHAMATU POS updates.</span>
                    </label>
                </div>

                <p class="form-privacy">We use your details only to respond to this enquiry. Read the <a href="{{ url('/privacy') }}">privacy notice</a>.</p>

                <button type="submit" class="button button--primary">{{ $submitLabel }}</button>
            </form>
        </div>
    </div>
</section>
