# Tadpole Modern Image Formats
Plugin was build at Shopware Hackathon in Duisburg 2023.  
Organized by [Firegento](https://shop.firegento.com/).

## Requirements
* `>= PHP 8.1`
* `>= Shopware 6.5`
* `ext-gd`

### Why you need this plugin?
* You do not want to use a professional image service like Thumbor
* The plugin will generate webp images to be used in your storefront
* This will reduce the download size and improve load times

### Features
* Adds picture tag to template with additional source to webp images
* Console command to generate webp images
* Subscriber on "media.entity.written" to creat webp after upload via admin

### Setup Guide
* Require the plugin via composer
* Install and activate the plugin via `bin/console plugin:install TadpoleModernImageFormats --activate`
* Run the console command via `bin/console media:image:convert`
* After that activate via plugin config in administration to use the modern image formats (set `useModernImageFormatsInFrontends` to `true`)

### Template
The HTML will look like this for example:
```
<picture>
    <source type="image/webp" srcset="http://localhost:8000/thumbnail/ae/e6/2c/1680008675/Deploy%20another%20day%202%20%283%29_800x800.png.webp 800w, http://localhost:8000/thumbnail/ae/e6/2c/1680008675/Deploy%20another%20day%202%20%283%29_1920x1920.png.webp 1920w, http://localhost:8000/thumbnail/ae/e6/2c/1680008675/Deploy%20another%20day%202%20%283%29_400x400.png.webp 400w" sizes="(min-width: 1200px) 280px, (min-width: 992px) 350px, (min-width: 768px) 390px, (min-width: 576px) 315px, (min-width: 0px) 500px, 100vw">
    <img src="http://localhost:8000/media/ae/e6/2c/1680008675/Deploy%20another%20day%202%20%283%29.png" srcset="http://localhost:8000/thumbnail/ae/e6/2c/1680008675/Deploy%20another%20day%202%20%283%29_800x800.png 800w, http://localhost:8000/thumbnail/ae/e6/2c/1680008675/Deploy%20another%20day%202%20%283%29_1920x1920.png 1920w, http://localhost:8000/thumbnail/ae/e6/2c/1680008675/Deploy%20another%20day%202%20%283%29_400x400.png 400w" sizes="(min-width: 1200px) 280px, (min-width: 992px) 350px, (min-width: 768px) 390px, (min-width: 576px) 315px, (min-width: 0px) 500px, 100vw" class="product-image is-standard" alt="Testproduct" title="Testproduct">
</picture>
```

### Next features
* AVIF Format Support
* Support for Main image, currently only thumbnails