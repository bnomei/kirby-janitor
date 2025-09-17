import Janitor from "./components/fields/Janitor.vue";

panel.plugin("bnomei/janitor", {
	fields: {
		janitor: Janitor
	},
	viewButtons: {
		janitor: Janitor
	},
	icons: {
		janitorLoader:
			'<svg viewBox="0 0 24 24" version="1.1" id="svg2" xmlns="http://www.w3.org/2000/svg" xmlns:svg="http://www.w3.org/2000/svg"><g fill="none" fill-rule="evenodd" id="g2" transform="matrix(1.2373578,0,0,1.2393776,2.1011378,2.0846672)"><g transform="translate(1,1)" stroke-width="1.75" id="g1"><circle cx="7" cy="7" r="7.2" stroke="#000" stroke-opacity=".2" id="circle1"/><path d="M 14.2,7 C 14.2,3 11,-0.2 7,-0.2" stroke="#000" id="path1"><animateTransform attributeName="transform" type="rotate" from="0 7 7" to="360 7 7" dur="1s" repeatCount="indefinite"/></path></g></g></svg>'
	}
});
