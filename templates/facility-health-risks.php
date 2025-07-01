<?php
/**
 * Template Part: Health Risks
 */

// Get facility type and impact data
$facility_type = get_post_meta(get_the_ID(), 'facility_type', true);
$impact_data = get_post_meta(get_the_ID(), 'impact_data', true) ?: array();

// Default health risks
$health_risks = array(
    'respiratory' => array(
        'title' => 'Respiratory Health',
        'description' => 'Air pollutants can cause or worsen respiratory conditions.',
        'risks' => array(
            'Increased risk of asthma and bronchitis',
            'Reduced lung function',
            'Chronic respiratory conditions'
        )
    ),
    'water' => array(
        'title' => 'Water Quality Impact',
        'description' => 'Contamination of local water sources can affect drinking water quality.',
        'risks' => array(
            'Potential groundwater contamination',
            'Impact on local water supplies',
            'Increased risk of waterborne illnesses'
        )
    ),
    'soil' => array(
        'title' => 'Soil Contamination',
        'description' => 'Industrial processes can lead to soil contamination.',
        'risks' => array(
            'Exposure through direct contact',
            'Food safety concerns for local agriculture',
            'Long-term environmental impact'
        )
    )
);

// Add facility-specific risks
if ($facility_type === 'industrial') {
    $health_risks['chemical'] = array(
        'title' => 'Chemical Exposure',
        'description' => 'Industrial chemicals may pose health risks.',
        'risks' => array(
            'Potential exposure to hazardous materials',
            'Long-term health effects',
            'Impact on vulnerable populations'
        )
    );
}
?>

<div class="bg-red-50 rounded-lg p-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Health Risks Assessment</h2>
        <div class="flex items-center space-x-2">
            <span class="text-2xl">⚠️</span>
            <span class="px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                High Risk Area
            </span>
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <?php foreach ($health_risks as $risk): ?>
        <div class="bg-white rounded-lg p-4 shadow">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="font-bold text-gray-900"><?php echo esc_html($risk['title']); ?></h3>
                    <p class="text-sm text-gray-600 mt-1"><?php echo esc_html($risk['description']); ?></p>
                    <ul class="mt-2 space-y-1">
                        <?php foreach ($risk['risks'] as $item): ?>
                        <li class="flex items-start text-sm">
                            <span class="flex-shrink-0 w-4 h-4 mt-1 mr-2">
                                <svg class="text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                            </span>
                            <span class="text-gray-700"><?php echo esc_html($item); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-6 bg-white rounded-lg p-4 shadow">
        <h3 class="font-bold text-gray-900 mb-2">Vulnerable Populations</h3>
        <p class="text-gray-600">The following groups may be particularly sensitive to these health risks:</p>
        <ul class="mt-2 grid grid-cols-2 gap-2">
            <li class="flex items-center text-sm text-gray-700">
                <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Children under 12
            </li>
            <li class="flex items-center text-sm text-gray-700">
                <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Elderly individuals
            </li>
            <li class="flex items-center text-sm text-gray-700">
                <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Pregnant women
            </li>
            <li class="flex items-center text-sm text-gray-700">
                <svg class="w-4 h-4 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Those with pre-existing conditions
            </li>
        </ul>
    </div>

    <div class="mt-6 p-4 border border-red-200 rounded-lg">
        <p class="text-sm text-gray-600">
            <strong>Disclaimer:</strong> This health risk assessment is based on available data and general environmental impact patterns. For specific health concerns, please consult with healthcare professionals and local environmental agencies.
        </p>
    </div>
</div> 