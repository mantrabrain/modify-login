const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const fs = require('fs');

// Custom plugin to clean CSS directory after build
class CleanCssJsPlugin {
    apply(compiler) {
        compiler.hooks.done.tap('CleanCssJsPlugin', (stats) => {
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
    entry: {
        'admin/js/settings': './src/admin/js/settings.js',
        'admin/css/tailwind': './src/admin/css/tailwind.css',
        'admin/css/settings': './src/admin/css/settings.css',
        'admin/css/login-logs': './src/admin/css/login-logs.css'
    },
    output: {
        path: path.resolve(__dirname, 'assets/dist'),
        filename: (pathData) => {
            // Only generate JS files for JS entries
            return pathData.chunk.name.includes('/js/') ? '[name].min.js' : '[name].css.js';
        },
        clean: true
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
                test: /\.css$/,
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
                    }
                ]
            }
        ]
    },
    plugins: [
        new CleanWebpackPlugin({
            cleanOnceBeforeBuildPatterns: ['**/*', '!.gitkeep'],
            cleanAfterEveryBuildPatterns: ['**/*.css.js', '**/*.css.js.map']
        }),
        new MiniCssExtractPlugin({
            filename: '[name].min.css'
        }),
        new CleanCssJsPlugin()
    ],
    optimization: {
        runtimeChunk: false,
        splitChunks: false
    },
    devtool: 'source-map'
}; 