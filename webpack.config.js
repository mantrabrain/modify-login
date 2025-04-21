const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const fs = require('fs');

// Clean dist directory before build
const cleanDistDirectory = () => {
    const distPath = path.resolve(__dirname, 'assets/dist');
    if (fs.existsSync(distPath)) {
        fs.rmSync(distPath, { recursive: true, force: true });
    }
};

// Function to get all entry points dynamically
function getEntryPoints() {
    const srcDir = path.resolve(__dirname, 'src');
    const entryPoints = {};

    function processDirectory(dir) {
        const items = fs.readdirSync(dir);
        
        items.forEach(item => {
            const fullPath = path.join(dir, item);
            const stat = fs.statSync(fullPath);
            
            if (stat.isDirectory()) {
                processDirectory(fullPath);
            } else {
                // Get the relative path from src directory
                const relativePath = path.relative(srcDir, fullPath);
                const ext = path.extname(relativePath);
                
                // Only process JS, CSS and SCSS files
                if (['.js', '.css', '.scss'].includes(ext)) {
                    // Remove the extension to create the entry name
                    const entryName = relativePath.slice(0, -ext.length);
                    // Use the relative path from src as the entry point
                    entryPoints[entryName] = './' + path.join('src', relativePath).replace(/\\/g, '/');
                }
            }
        });
    }

    processDirectory(srcDir);
    return entryPoints;
}

// Custom plugin to clean dist directory before each build and CSS JS files after build
class CleanDistPlugin {
    apply(compiler) {
        // Clean dist directory before build
        compiler.hooks.beforeRun.tap('CleanDistPlugin', () => {
            cleanDistDirectory();
        });
        compiler.hooks.watchRun.tap('CleanDistPlugin', () => {
            cleanDistDirectory();
        });

        // Clean CSS JS files after build
        compiler.hooks.done.tap('CleanDistPlugin', (stats) => {
            if (!stats.hasErrors()) {
                const cssDir = path.join(compiler.options.output.path, 'admin/css');
                if (fs.existsSync(cssDir)) {
                    const files = fs.readdirSync(cssDir);
                    files.forEach(file => {
                        if (file.endsWith('.js') || file.endsWith('.js.map')) {
                            fs.unlinkSync(path.join(cssDir, file));
                        }
                    });
                }
            }
        });
    }
}

module.exports = {
    mode: 'production',
    entry: getEntryPoints(),
    output: {
        path: path.resolve(__dirname, 'assets/dist'),
        filename: '[name].min.js',
        clean: true
    },
    resolve: {
        extensions: ['.js', '.jsx', '.scss', '.css'],
        modules: ['node_modules', path.resolve(__dirname, 'src')]
    },
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env']
                    }
                }
            },
            {
                test: /\.(scss|css)$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    'css-loader',
                    {
                        loader: 'postcss-loader',
                        options: {
                            postcssOptions: {
                                plugins: [
                                    require('tailwindcss'),
                                    require('autoprefixer')
                                ]
                            }
                        }
                    },
                    'sass-loader'
                ]
            }
        ]
    },
    plugins: [
        new CleanDistPlugin(),
        new CleanWebpackPlugin({
            cleanOnceBeforeBuildPatterns: ['**/*']
        }),
        new MiniCssExtractPlugin({
            filename: '[name].min.css'
        })
    ],
    optimization: {
        minimize: true,
        minimizer: [
            new TerserPlugin({
                terserOptions: {
                    format: {
                        comments: false,
                    },
                },
                extractComments: false,
            }),
        ],
    },
    devtool: 'source-map'
}; 