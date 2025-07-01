<?php get_header(); ?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="bg-white rounded-xl shadow-lg p-8 text-center">
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Facility Not Found</h2>
        <p class="text-gray-600 mb-6">We couldn't find the facility you're looking for. This could be because:</p>
        <ul class="text-left max-w-lg mx-auto mb-8 space-y-2 text-gray-600">
            <li>• The facility ID is incorrect</li>
            <li>• The facility has been removed from our database</li>
            <li>• There was an error accessing the facility information</li>
        </ul>
        <div class="space-y-4">
            <a href="<?php echo home_url('/'); ?>" 
               class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Return to Search
            </a>
            <p class="text-sm text-gray-500">
                If you believe this is an error, please contact our support team.
            </p>
        </div>
    </div>
</div>

<?php get_footer(); ?> 