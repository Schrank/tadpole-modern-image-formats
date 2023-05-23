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

### Next features
* Listener that generates webp images after upload
* AVIF Format Support
* Support for Media Gallery