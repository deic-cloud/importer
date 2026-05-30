const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = (env, argv) => {
	const isDev = argv.mode === 'development'
	return {
		mode:    isDev ? 'development' : 'production',
		devtool: isDev ? 'cheap-source-map' : false,
		entry: {
			'importer-main':     path.join(__dirname, 'src', 'main.js'),
			'importer-personal': path.join(__dirname, 'src', 'personal.js'),
		},
		output: {
			path:     path.join(__dirname, 'js'),
			filename: '[name].js',
			clean:    false,
		},
		resolve: {
			extensions: ['.js', '.vue'],
		},
		module: {
			rules: [
				{
					test: /\.vue$/,
					loader: 'vue-loader',
				},
				{
					test: /\.js$/,
					exclude: /node_modules/,
					loader: 'babel-loader',
				},
				{
					test: /\.css$/,
					use: ['vue-style-loader', 'css-loader'],
				},
			],
		},
		plugins: [new VueLoaderPlugin()],
	}
}
