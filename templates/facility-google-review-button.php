<?php
/**
 * Template Part: Google Review Button
 */
?>
<a href="https://www.google.com/search?q=<?php echo urlencode(get_the_title() . ' review'); ?>"
   target="_blank"
   class="inline-block mt-4 px-4 py-2 bg-green-600 text-white font-bold rounded hover:bg-green-700 transition">
  Leave a Google Review
</a>
