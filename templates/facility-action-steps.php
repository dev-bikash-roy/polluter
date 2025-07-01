<?php
/**
 * Template Part: Action Steps
 */

// Get facility data
$facility_phone = get_post_meta(get_the_ID(), 'facility_phone', true);
$facility_name = get_the_title();
$facility_place_id = get_post_meta(get_the_ID(), 'place_id', true);
?>
<div class="bg-blue-50 rounded-lg p-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Take Action</h2>
    <div class="space-y-6">
        <p class="text-gray-700">Here are concrete steps you can take to help address environmental concerns:</p>
        
        <!-- Call Facility -->
        <div class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <span class="text-2xl">📞</span>
                </div>
                <div>
                    <h3 class="font-bold text-blue-800 text-lg">1. Call and Ask About Pollution Reduction</h3>
                    <p class="text-gray-600 mt-2">Contact the facility directly to inquire about their pollution reduction efforts and plans.</p>
                    <?php if ($facility_phone): ?>
                        <a href="tel:<?php echo esc_attr($facility_phone); ?>" class="inline-flex items-center mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <span>Call <?php echo esc_html($facility_phone); ?></span>
                        </a>
                    <?php endif; ?>
                    <div class="mt-3 text-sm text-gray-600">
                        <p class="font-medium">Suggested questions:</p>
                        <ul class="list-disc ml-5 mt-1 space-y-1">
                            <li>What specific measures are you taking to reduce pollution?</li>
                            <li>Do you have an environmental impact reduction timeline?</li>
                            <li>How do you monitor and report emissions?</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Write Google Review -->
        <div class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <span class="text-2xl">✍️</span>
                </div>
                <div>
                    <h3 class="font-bold text-blue-800 text-lg">2. Write a Google Review</h3>
                    <p class="text-gray-600 mt-2">Share your environmental concerns on their Google Business profile to raise awareness.</p>
                    <?php if ($facility_place_id): ?>
                        <a href="https://search.google.com/local/writereview?placeid=<?php echo esc_attr($facility_place_id); ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="inline-flex items-center mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <span>Write Review</span>
                        </a>
                    <?php endif; ?>
                    <div class="mt-3 text-sm text-gray-600">
                        <p class="font-medium">Tips for effective reviews:</p>
                        <ul class="list-disc ml-5 mt-1 space-y-1">
                            <li>Be factual and specific about environmental concerns</li>
                            <li>Include dates and observations</li>
                            <li>Mention any communication attempts with the facility</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact Politicians -->
        <div class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <span class="text-2xl">🏛️</span>
                </div>
                <div>
                    <h3 class="font-bold text-blue-800 text-lg">3. Contact Local Politicians</h3>
                    <p class="text-gray-600 mt-2">Reach out to your representatives about environmental concerns in your area.</p>
                    <a href="https://www.usa.gov/elected-officials" target="_blank" rel="noopener noreferrer" 
                       class="inline-flex items-center mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <span>Find Representatives</span>
                    </a>
                    <div class="mt-3 text-sm text-gray-600">
                        <p class="font-medium">Key points to mention:</p>
                        <ul class="list-disc ml-5 mt-1 space-y-1">
                            <li>Specific environmental concerns about this facility</li>
                            <li>Impact on local community health</li>
                            <li>Request for environmental oversight</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sign Petition -->
        <div class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <span class="text-2xl">📝</span>
                </div>
                <div>
                    <h3 class="font-bold text-blue-800 text-lg">4. Sign and Share Petitions</h3>
                    <p class="text-gray-600 mt-2">Support or start petitions for environmental accountability.</p>
                    <div class="flex space-x-3 mt-3">
                        <a href="https://www.change.org/search?q=environmental" target="_blank" rel="noopener noreferrer" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <span>Find Petitions</span>
                        </a>
                        <a href="https://www.change.org/start-a-petition" target="_blank" rel="noopener noreferrer" 
                           class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <span>Start Petition</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Share on Social -->
        <div class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <span class="text-2xl">📢</span>
                </div>
                <div>
                    <h3 class="font-bold text-blue-800 text-lg">5. Share on Social Media</h3>
                    <p class="text-gray-600 mt-2">Raise awareness by sharing information about environmental concerns.</p>
                    <div class="flex space-x-3 mt-3">
                        <?php
                        $share_text = urlencode("Environmental concerns at " . $facility_name . ". Learn more and take action:");
                        $share_url = urlencode(get_permalink());
                        ?>
                        <a href="https://twitter.com/intent/tweet?text=<?php echo $share_text; ?>&url=<?php echo $share_url; ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="inline-flex items-center px-4 py-2 bg-[#1DA1F2] text-white rounded-lg hover:bg-[#1a8cd8]">
                            <span>Share on X</span>
                        </a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $share_url; ?>" 
                           target="_blank" rel="noopener noreferrer" 
                           class="inline-flex items-center px-4 py-2 bg-[#1877F2] text-white rounded-lg hover:bg-[#166fe5]">
                            <span>Share on Facebook</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Plant Trees -->
        <div class="bg-white p-6 rounded-lg shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-start space-x-4">
                <div class="flex-shrink-0">
                    <span class="text-2xl">🌱</span>
                </div>
                <div>
                    <h3 class="font-bold text-blue-800 text-lg">6. Plant Bioremediation Trees and Shrubs</h3>
                    <p class="text-gray-600 mt-2">Support natural pollution reduction through strategic planting.</p>
                    <div class="mt-3 text-sm text-gray-600">
                        <p class="font-medium">Recommended species for pollution absorption:</p>
                        <ul class="list-disc ml-5 mt-1 space-y-1">
                            <li>Poplar trees (fast-growing, deep roots)</li>
                            <li>Willow trees (effective for soil cleanup)</li>
                            <li>Indian Mustard (removes heavy metals)</li>
                            <li>Sunflowers (absorbs toxins and heavy metals)</li>
                        </ul>
                        <a href="https://www.epa.gov/contaminated-sites/phytotechnology-project-profiles" 
                           target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center mt-3 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <span>Learn More About Bioremediation</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div> 