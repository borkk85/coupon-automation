<!-- templates/admin-brands.php (no Tailwind) -->
<div class="wrap" id="coupon-automation-brands">
  <div class="ca-container">
    <!-- Header -->
    <div class="ca-hero">
      <h1>Brand Population Tool</h1>
      <p>Bulk update brand descriptions and metadata</p>
    </div>

    <!-- Info Cards -->
    <div class="ca-grid ca-grid-3 ca-mb-6">
      <div class="ca-card">
        <div class="ca-flex-between">
          <div>
            <p class="ca-card-label">Total Brands</p>
            <p class="ca-card-value"><?php echo wp_count_terms('brands', ['hide_empty' => false]); ?></p>
          </div>
          <div class="ca-card-icon" style="color: var(--ca-purple);">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
          </div>
        </div>
      </div>

      <div class="ca-card">
        <div class="ca-flex-between">
          <div>
            <p class="ca-card-label">Without Description</p>
            <p class="ca-card-value"><?php $brands_without_desc = get_terms(['taxonomy'=>'brands','hide_empty'=>false,'description__like'=>'']); echo count($brands_without_desc); ?></p>
          </div>
          <div class="ca-card-icon" style="color: var(--ca-yellow);">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
          </div>
        </div>
      </div>

      <div class="ca-card">
        <div class="ca-flex-between">
          <div>
            <p class="ca-card-label">Processing Status</p>
            <p class="ca-card-label" id="processing-status" style="font-weight:600;color:#1f2937;">Ready</p>
          </div>
          <div class="ca-card-icon" style="color: var(--ca-green);">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="ca-section">
      <div class="ca-section-header">
        <h2 class="ca-section-title">Populate Brand Data</h2>
        <p class="ca-muted" style="margin-top:.5rem;">This tool will process all brands and generate missing descriptions, "Why We Love" sections, and other metadata using AI.</p>
      </div>
      <div class="ca-section-body">
        <!-- Control Panel -->
        <div class="ca-mb-6">
          <div class="ca-buttons">
            <button id="start-population" class="ca-btn ca-btn-primary">
              <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg>
              Start Population
            </button>
            <button id="stop-population" class="ca-btn ca-btn-danger ca-hidden">
              <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
              Stop Processing
            </button>
          </div>
        </div>

        <!-- Progress Bar -->
        <div class="ca-progress ca-hidden brand-population-progress">
          <div class="ca-progress-top">
            <span class="ca-card-label" style="font-weight:600;color:#374151;">Processing Brands</span>
            <span class="ca-muted"><span class="processed-count">0</span> / <span class="total-count">0</span></span>
          </div>
          <div class="ca-progress-track"><div class="ca-progress-bar progress"></div></div>
        </div>

        <!-- Options -->
        <div class="ca-panel ca-mb-6">
          <h3 style="font-size:1.125rem;font-weight:600;margin:0 0 .75rem;">Processing Options</h3>
          <div style="display:flex;flex-direction:column;gap:.5rem;">
            <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" id="update-descriptions" checked> <span>Generate missing descriptions</span></label>
            <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" id="update-why-we-love" checked> <span>Generate \"Why We Love\" sections</span></label>
            <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" id="update-images" checked> <span>Download missing brand images</span></label>
            <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" id="update-hashtags" checked> <span>Add hashtags to descriptions</span></label>
          </div>
        </div>

        <!-- Activity Log -->
        <div class="ca-panel">
          <h3 style="font-size:1.125rem;font-weight:600;margin:0 0 .75rem;">Activity Log</h3>
          <div class="log-entries" style="max-height:24rem; overflow-y:auto;">
            <p class="ca-muted">No activity yet. Click "Start Population" to begin.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Stats Section -->
    <div class="ca-section ca-mt-6">
      <div class="ca-section-body">
        <h3 class="ca-section-title" style="margin-bottom:1rem;">Brand Statistics</h3>
        <div class="ca-grid ca-grid-4">
          <div style="text-align:center;">
            <p class="ca-card-value" style="color: var(--ca-purple); font-size: 1.875rem;">
              <?php $brands_with_image = 0; $all_brands = get_terms(['taxonomy'=>'brands','hide_empty'=>false]); foreach($all_brands as $brand){ if(get_field('featured_image','brands_' . $brand->term_id)){ $brands_with_image++; } } echo $brands_with_image; ?>
            </p>
            <p class="ca-muted" style="font-size:.875rem;">With Images</p>
          </div>
          <div style="text-align:center;">
            <p class="ca-card-value" style="color: var(--ca-green); font-size: 1.875rem;">
              <?php $popular_brands = 0; foreach($all_brands as $brand){ if(get_field('popular_brand','brands_' . $brand->term_id)){ $popular_brands++; } } echo $popular_brands; ?>
            </p>
            <p class="ca-muted" style="font-size:.875rem;">Popular Brands</p>
          </div>
          <div style="text-align:center;">
            <p class="ca-card-value" style="color: var(--ca-blue); font-size: 1.875rem;">
              <?php $brands_with_link = 0; foreach($all_brands as $brand){ if(get_field('affiliate_link','brands_' . $brand->term_id)){ $brands_with_link++; } } echo $brands_with_link; ?>
            </p>
            <p class="ca-muted" style="font-size:.875rem;">With Affiliate Links</p>
          </div>
          <div style="text-align:center;">
            <p class="ca-card-value" style="color: #fb923c; font-size: 1.875rem;">
              <?php $brands_with_love = 0; foreach($all_brands as $brand){ if(get_field('why_we_love','brands_' . $brand->term_id)){ $brands_with_love++; } } echo $brands_with_love; ?>
            </p>
            <p class="ca-muted" style="font-size:.875rem;">With "Why We Love"</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>