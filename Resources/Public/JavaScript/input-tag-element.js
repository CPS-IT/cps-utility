import Tagify from '@cpsit/cps-utility/tagify.js';
import DocumentService from '@typo3/core/document-service.js';

class InputTagElement {
    constructor() {
        DocumentService.ready().then(() => {
            const inputTagElements = document.querySelectorAll('input[data-role="tagsinput"]');
            inputTagElements.forEach(inputTagElement => {
                if (inputTagElement instanceof HTMLInputElement) {
                    new Tagify(inputTagElement, {
                        //originalInputValueFormat: valuesArr => valuesArr.map(item => item.value).join(',')
                    });
                }
            });
        });
    }
}
export default new InputTagElement;
