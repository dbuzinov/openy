/*global location b:true*/
/*global drupalSettings b:true*/
/*eslint no-restricted-globals: ["warn", "drupalSettings"]*/
import React, {Component} from 'react';
import {connect} from 'react-redux';
import './App.css';
import "slick-carousel/slick/slick.css";
import "slick-carousel/slick/slick-theme.css";
import HeaderAlertItem from './components/HeaderAlertItem/HeaderAlertItem';
import Slider from "react-slick";

import {fetchAlerts} from "./actions/backend";

class App extends Component {
    constructor(props) {
        super(props);
        this.next = this.next.bind(this);
        this.previous = this.previous.bind(this);
    }
    next() {
        this.slider.slickNext();
    }
    previous() {
        this.slider.slickPrev();
    }

    render() {
        var sliderSettings = {
            dots: false,
            infinite: false,
            speed: 500,
            slidesToShow: 1,
            slidesToScroll: 1,
            arrows: false,
            variableWidth: false,
            centerMode: true,
            centerPadding: '0px',
            // className: 'slick__slider',
        };
        const HeaderAlertItemList = () => {
            if (this.props.header) {
                return Object.keys(this.props.header).map(i => {
                    return this.props.header[i].map(a => {
                        return <HeaderAlertItem key={a.title} label={a.title} iconColor={a.iconColor} linkTitle={a.linkText} linkUrl={a.linkUrl}
                                                description={a.description} txtColor={a.textColor} bgColor={a.bgColor}/>
                    });
                })
            }
            else {
                return null;
            }

        };
        return (
            <div className="App">
                <div className={'header-alerts-list alerts header-alerts-list-processed'}>
                    <Slider ref={c => (this.slider = c)} {...sliderSettings}>
                        {HeaderAlertItemList()}
                    </Slider>
                    <div className="container">
                        <div className="slick__counter"><span className="current"></span> of <span
                            className="total"></span></div>
                        <div className="slick__arrow">
                            <a href="#" data-role="none" className="slick-prev slick-arrow" role="button"
                               aria-disabled="true" onClick={this.previous}>Previous</a><a href="#" data-role="none"
                                                                            className="slick-next slick-arrow"
                                                                            role="button" aria-disabled="false"
                                                                                           onClick={this.next}>Next</a>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    componentDidMount() {
        let pathname = location.pathname.substring(1);
        let baseUrl = drupalSettings.path.baseUrl;
        if (baseUrl === '/') {
            this.props.fetchAlerts(`/${pathname}`);
            return;
        } else {
            let uri = `/${pathname}`.replace(new RegExp(baseUrl, 'g'),'');
            this.props.fetchAlerts(uri);
        }
    }
}

const mapDispatchToProps = dispatch => {
    return {
        fetchAlerts: uri => {
            dispatch(fetchAlerts(uri))
        }
    }
}

const mapStateToProps = state => {
    return {
        alerts: state.init.alerts,
        header: state.init.alerts.header,
        footer: state.init.alerts.footer,
    }
}

const AppHeader = connect(
    mapStateToProps,
    mapDispatchToProps
)(App)

export default AppHeader;
